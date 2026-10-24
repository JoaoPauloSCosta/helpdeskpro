<?php

use App\Enums\AdminEvent;
use App\Enums\TicketEvent;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create(['email' => 'admin@example.com']);
    $this->support = Department::factory()->create(['name' => 'Suporte']);
    $this->finance = Department::factory()->create(['name' => 'Financeiro']);
    $this->target = User::factory()->withRole('technician')->create([
        'name' => 'Bruno Lima',
        'email' => 'bruno@example.com',
        'department_id' => $this->support->id,
    ]);
});

function slugRoleId(string $slug): int
{
    return Role::where('slug', $slug)->value('id');
}

/** Dados atuais do alvo como o formulário os envia, com ajustes por teste. */
function editData(User $user, array $overrides = []): array
{
    $user->refresh();

    return [
        'name' => $user->name,
        'email' => $user->email,
        'role_id' => $user->role_id,
        'department_id' => $user->department_id,
        'status' => $user->is_active ? 'active' : 'inactive',
        'version' => $user->editVersion(),
        ...$overrides,
    ];
}

function patchUser(User $operator, User $target, array $data): TestResponse
{
    return test()->actingAs($operator)->patch("/admin/users/{$target->id}", $data);
}

/** Papel personalizado só com `users.manage`, sem as demais permissões administrativas. */
function limitedUserManager(): User
{
    $role = Role::create(['name' => 'Recursos Humanos', 'slug' => 'hr']);
    $role->permissions()->attach(Permission::where('slug', 'users.manage')->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get("/admin/users/{$this->target->id}/edit")->assertRedirect('/login');
    $this->patch("/admin/users/{$this->target->id}", editData($this->target, ['name' => 'X']))->assertRedirect('/login');

    expect($this->target->fresh()->name)->toBe('Bruno Lima');
});

test('users without users.manage can neither view nor edit', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get("/admin/users/{$this->target->id}/edit")
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->missing('user'));

    patchUser($user, $this->target, editData($this->target, ['name' => 'Invasor', 'status' => 'inactive']))->assertForbidden();

    expect($this->target->fresh())
        ->name->toBe('Bruno Lima')
        ->is_active->toBeTrue();
})->with(['requester', 'technician', 'manager']);

test('unauthorized users cannot tell which identifiers exist', function () {
    $requester = User::factory()->withRole('requester')->create();

    $this->actingAs($requester)->get('/admin/users/999999/edit')->assertForbidden();
    $this->actingAs($requester)->patch('/admin/users/999999', ['name' => 'x'])->assertForbidden();
});

test('inactive administrators are denied', function () {
    $inactive = User::factory()->withRole('admin')->inactive()->create();

    $this->actingAs($inactive)->get("/admin/users/{$this->target->id}/edit")->assertForbidden();
    patchUser($inactive, $this->target, editData($this->target, ['name' => 'X']))->assertForbidden();
});

test('missing users return not found to authorized operators', function () {
    $this->actingAs($this->admin)->get('/admin/users/999999/edit')->assertNotFound();
    $this->actingAs($this->admin)->patch('/admin/users/999999', ['name' => 'x'])->assertNotFound();
});

/* ---------- Carregamento ---------- */

test('the form receives the real data and no sensitive attributes', function () {
    Department::factory()->create(['name' => 'Antigo', 'is_active' => false]);

    $response = $this->actingAs($this->admin)
        ->get("/admin/users/{$this->target->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Edit')
            ->where('user', [
                'id' => $this->target->id,
                'name' => 'Bruno Lima',
                'email' => 'bruno@example.com',
                'role' => 'Técnico',
                'is_active' => true,
                'is_self' => false,
                'created_at' => $this->target->created_at->toIso8601String(),
            ])
            ->where('values', [
                'name' => 'Bruno Lima',
                'email' => 'bruno@example.com',
                'role_id' => (string) slugRoleId('technician'),
                'department_id' => (string) $this->support->id,
                'status' => 'active',
            ])
            ->has('options.roles', 4)
            // Departamentos inativos não são oferecidos, exceto o atual.
            ->where('options.departments', [
                ['value' => (string) $this->finance->id, 'label' => 'Financeiro'],
                ['value' => (string) $this->support->id, 'label' => 'Suporte'],
            ])
            ->where('can.changeAccess', true)
            ->where('version', $this->target->editVersion()));

    $props = json_encode($response->viewData('page')['props']);
    expect($props)->not->toContain($this->target->password)
        ->and($props)->not->toContain($this->admin->password)
        ->and($props)->not->toContain('remember_token')
        ->and($props)->not->toContain('"password"');
});

test('the current inactive department stays selectable', function () {
    $this->support->update(['is_active' => false]);

    $this->actingAs($this->admin)
        ->get("/admin/users/{$this->target->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('options.departments.1', [
            'value' => (string) $this->support->id,
            'label' => 'Suporte (inativo)',
        ]));

    patchUser($this->admin, $this->target, editData($this->target, ['name' => 'Bruno L.']))->assertSessionHasNoErrors();

    expect($this->target->fresh()->department_id)->toBe($this->support->id);
});

/* ---------- Validação ---------- */

test('invalid values are rejected without partial changes', function (array $overrides, string $field) {
    patchUser($this->admin, $this->target, editData($this->target, ['name' => 'Nome Novo', ...$overrides]))
        ->assertSessionHasErrors($field);

    expect($this->target->fresh()->name)->toBe('Bruno Lima');
})->with([
    'empty name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'invalid e-mail' => [['email' => 'sem-arroba'], 'email'],
    'empty e-mail' => [['email' => ''], 'email'],
    'missing role' => [['role_id' => 999999], 'role_id'],
    'empty role' => [['role_id' => ''], 'role_id'],
    'missing department' => [['department_id' => 999999], 'department_id'],
    'unknown status' => [['status' => 'suspended'], 'status'],
    'missing version' => [['version' => ''], 'version'],
]);

test('switching to an inactive department is rejected', function () {
    $inactive = Department::factory()->create(['is_active' => false]);

    patchUser($this->admin, $this->target, editData($this->target, ['department_id' => $inactive->id]))
        ->assertSessionHasErrors(['department_id' => 'Selecione um departamento ativo.']);
});

test('duplicate e-mails are rejected, ignoring case', function () {
    User::factory()->create(['email' => 'outro@example.com']);

    patchUser($this->admin, $this->target, editData($this->target, ['email' => 'OUTRO@example.com']))
        ->assertSessionHasErrors(['email' => 'Já existe um usuário cadastrado com este e-mail.']);

    expect($this->target->fresh()->email)->toBe('bruno@example.com');
});

test('keeping the same e-mail is not a duplicate', function () {
    patchUser($this->admin, $this->target, editData($this->target, ['name' => 'Bruno Lima Souza']))
        ->assertSessionHasNoErrors();

    expect($this->target->fresh()->name)->toBe('Bruno Lima Souza');
});

/* ---------- Persistência ---------- */

test('valid changes are persisted and the operator returns to the listing', function () {
    patchUser($this->admin, $this->target, editData($this->target, [
        'name' => 'Bruno Lima Souza',
        'email' => ' Bruno.Souza@Example.com ',
        'role_id' => slugRoleId('manager'),
        'department_id' => $this->finance->id,
    ]))
        ->assertRedirect(route('admin.users.index', ['q' => 'bruno.souza@example.com']))
        ->assertSessionHas('success', 'Alterações de Bruno Lima Souza salvas.');

    expect($this->target->fresh())
        ->name->toBe('Bruno Lima Souza')
        ->email->toBe('bruno.souza@example.com')
        ->role_id->toBe(slugRoleId('manager'))
        ->department_id->toBe($this->finance->id)
        ->is_active->toBeTrue();
});

test('the department can be removed', function () {
    patchUser($this->admin, $this->target, editData($this->target, ['department_id' => null]))->assertSessionHasNoErrors();

    expect($this->target->fresh()->department_id)->toBeNull();
});

test('saving without changes records nothing', function () {
    patchUser($this->admin, $this->target, editData($this->target))
        ->assertRedirect(route('admin.users.edit', $this->target))
        ->assertSessionHas('info', 'Nenhuma alteração para salvar.');

    expect(AuditLog::count())->toBe(0);
});

test('a stale form is rejected as a conflict', function () {
    $stale = editData($this->target, ['name' => 'Versão antiga']);
    $this->target->forceFill(['name' => 'Alterado por outra pessoa'])->save();

    patchUser($this->admin, $this->target, $stale)->assertSessionHasErrors('conflict');

    expect($this->target->fresh()->name)->toBe('Alterado por outra pessoa')
        ->and(AuditLog::count())->toBe(0);
});

test('protected fields sent manually are ignored', function () {
    $originalPassword = $this->target->password;
    $verifiedAt = $this->target->email_verified_at;

    patchUser($this->admin, $this->target, editData($this->target, [
        'name' => 'Bruno Atualizado',
        'password' => 'Escolhida#2026',
        'password_confirmation' => 'Escolhida#2026',
        'email_verified_at' => null,
        'remember_token' => 'forjado',
        'is_active' => false,
        'id' => 999999,
        'created_at' => '2000-01-01 00:00:00',
    ]))->assertSessionHasNoErrors();

    $fresh = $this->target->fresh();

    expect($fresh->id)->toBe($this->target->id)
        ->and($fresh->password)->toBe($originalPassword)
        ->and(Hash::check('Escolhida#2026', $fresh->password))->toBeFalse()
        ->and($fresh->email_verified_at?->toIso8601String())->toBe($verifiedAt->toIso8601String())
        ->and($fresh->remember_token)->not->toBe('forjado')
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->created_at->year)->not->toBe(2000);
});

test('changing the e-mail follows the profile policy and keeps authentication working', function () {
    Password::broker()->createToken($this->target);

    patchUser($this->admin, $this->target, editData($this->target, ['email' => 'novo@example.com']))->assertSessionHasNoErrors();

    $fresh = $this->target->fresh();

    expect($fresh->email_verified_at)->toBeNull()
        ->and(DB::table('password_reset_tokens')->where('email', 'bruno@example.com')->exists())->toBeFalse();

    auth()->logout();

    // A senha não muda; o login passa a usar o novo endereço.
    $this->post('/login', ['email' => 'bruno@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => 'novo@example.com', 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($fresh);
});

/* ---------- Papéis e elevação de privilégios ---------- */

test('operators cannot grant roles with administrative permissions they lack', function () {
    $operator = limitedUserManager();
    // O Técnico tem `audit.view` (RF-19, v1.8): o operador limitado não edita nem concede esse papel.
    $target = User::factory()->withRole('requester')->create(['department_id' => $this->support->id]);

    patchUser($operator, $target, editData($target, ['role_id' => slugRoleId('admin')]))
        ->assertSessionHasErrors(['role_id' => 'Você não tem permissão para atribuir este papel.']);
    patchUser($operator, $target, editData($target, ['role_id' => slugRoleId('manager')]))
        ->assertSessionHasErrors('role_id');
    patchUser($operator, $target, editData($target, ['role_id' => slugRoleId('technician')]))
        ->assertSessionHasErrors('role_id');

    expect($target->fresh()->role_id)->toBe(slugRoleId('requester'));

    $role = Role::create(['name' => 'Operacional', 'slug' => 'ops']);
    patchUser($operator, $target, editData($target, ['role_id' => $role->id]))->assertSessionHasNoErrors();
    expect($target->fresh()->role_id)->toBe($role->id);
});

test('operators cannot edit users more privileged than themselves', function () {
    $operator = limitedUserManager();

    $this->actingAs($operator)->get("/admin/users/{$this->admin->id}/edit")->assertForbidden();

    // Trocar o e-mail permitiria assumir a conta pela redefinição de senha.
    patchUser($operator, $this->admin, editData($this->admin, ['email' => 'tomada@example.com']))->assertForbidden();
    patchUser($operator, $this->admin, editData($this->admin, ['status' => 'inactive']))->assertForbidden();

    expect($this->admin->fresh())
        ->email->toBe('admin@example.com')
        ->is_active->toBeTrue();

    // A listagem também não oferece a ação.
    $this->actingAs($operator)
        ->get('/admin/users?q=admin@example.com')
        ->assertInertia(fn (Assert $page) => $page->where('users.data.0.can_edit', false));
});

test('the form only offers roles the operator can grant', function () {
    $target = User::factory()->withRole('requester')->create(['department_id' => $this->support->id]);

    $this->actingAs(limitedUserManager())
        ->get("/admin/users/{$target->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where(
            'options.roles',
            fn ($roles) => collect($roles)->pluck('label')->sort()->values()->all() === ['Recursos Humanos', 'Solicitante'],
        ));
});

test('users without a role can receive one', function () {
    $registered = User::factory()->create(['email' => 'cadastro@example.com']);

    patchUser($this->admin, $registered, editData($registered, ['role_id' => slugRoleId('requester')]))
        ->assertSessionHasNoErrors();

    expect($registered->fresh()->role_id)->toBe(slugRoleId('requester'))
        ->and(AuditLog::sole()->old_values)->toBe(['role' => null]);
});

/* ---------- Própria conta ---------- */

test('operators cannot change their own role or status', function () {
    $this->actingAs($this->admin)
        ->get("/admin/users/{$this->admin->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('user.is_self', true)
            ->where('can.changeAccess', false)
            ->where('options.roles', []));

    patchUser($this->admin, $this->admin, editData($this->admin, ['status' => 'inactive']))->assertForbidden();
    patchUser($this->admin, $this->admin, editData($this->admin, ['role_id' => slugRoleId('requester')]))->assertForbidden();

    expect($this->admin->fresh())
        ->is_active->toBeTrue()
        ->role_id->toBe(slugRoleId('admin'));
});

test('operators can update their own name and e-mail', function () {
    $data = editData($this->admin, ['name' => 'Admin Renomeado']);
    unset($data['role_id'], $data['status']);

    patchUser($this->admin, $this->admin, $data)->assertSessionHasNoErrors();

    expect($this->admin->fresh()->name)->toBe('Admin Renomeado');
});

/* ---------- Inativação ---------- */

test('operators without users.manage cannot deactivate users', function () {
    $manager = User::factory()->withRole('manager')->create();

    patchUser($manager, $this->target, editData($this->target, ['status' => 'inactive']))->assertForbidden();

    expect($this->target->fresh()->is_active)->toBeTrue();
});

test('deactivation keeps the history and blocks protected operations', function () {
    config(['session.driver' => 'database']);

    $requested = Ticket::factory()->create(['requester_id' => $this->target->id, 'department_id' => $this->support->id]);
    $assigned = Ticket::factory()->create(['assigned_to' => $this->target->id]);
    $comment = TicketComment::factory()->create(['ticket_id' => $assigned->id, 'user_id' => $this->target->id]);
    $assigned->histories()->forceCreate(['user_id' => $this->target->id, 'event_type' => TicketEvent::CommentAdded, 'created_at' => now()]);
    DB::table('sessions')->insert(['id' => 'sessao-do-bruno', 'user_id' => $this->target->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('sessions')->insert(['id' => 'sessao-de-outro', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => time()]);
    $rememberToken = $this->target->remember_token;

    patchUser($this->admin, $this->target, editData($this->target, ['status' => 'inactive']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Usuário Bruno Lima inativado. O histórico foi preservado.');

    $fresh = $this->target->fresh();

    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->remember_token)->not->toBe($rememberToken)
        ->and($requested->fresh()->requester_id)->toBe($this->target->id)
        ->and($assigned->fresh()->assigned_to)->toBe($this->target->id)
        ->and($comment->fresh())->not->toBeNull()
        ->and($assigned->histories()->where('user_id', $this->target->id)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $this->target->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'sessao-de-outro')->exists())->toBeTrue();

    $this->actingAs($fresh)->get('/tickets')->assertForbidden();
});

test('reactivation is audited separately', function () {
    $inactive = User::factory()->withRole('requester')->inactive()->create();

    patchUser($this->admin, $inactive, editData($inactive, ['status' => 'active']))
        ->assertSessionHas('success', "Usuário {$inactive->name} reativado.");

    expect($inactive->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::sole()->event_type)->toBe(AdminEvent::UserActivated);
});

test('changing the department does not touch existing tickets', function () {
    $ticket = Ticket::factory()->create(['requester_id' => $this->target->id, 'department_id' => $this->support->id]);

    patchUser($this->admin, $this->target, editData($this->target, ['department_id' => $this->finance->id]))
        ->assertSessionHasNoErrors();

    expect($this->target->fresh()->department_id)->toBe($this->finance->id)
        ->and($ticket->fresh()->department_id)->toBe($this->support->id)
        ->and($ticket->fresh()->requester_id)->toBe($this->target->id);
});

/* ---------- Auditoria e atomicidade ---------- */

test('changes are audited with the operator, previous and new values, and no secrets', function () {
    patchUser($this->admin, $this->target, editData($this->target, [
        'name' => 'Bruno Souza',
        'role_id' => slugRoleId('requester'),
        'department_id' => $this->finance->id,
        'status' => 'inactive',
    ]));

    $logs = AuditLog::orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->event_type)->toBe(AdminEvent::UserUpdated)
        ->and($logs[0]->user_id)->toBe($this->admin->id)
        ->and($logs[0]->auditable->is($this->target))->toBeTrue()
        ->and($logs[0]->created_at)->not->toBeNull()
        ->and($logs[0]->old_values)->toBe([
            'name' => 'Bruno Lima',
            'role' => ['id' => slugRoleId('technician'), 'label' => 'Técnico'],
            'department' => ['id' => $this->support->id, 'label' => 'Suporte'],
        ])
        ->and($logs[0]->new_values)->toBe([
            'name' => 'Bruno Souza',
            'role' => ['id' => slugRoleId('requester'), 'label' => 'Solicitante'],
            'department' => ['id' => $this->finance->id, 'label' => 'Financeiro'],
        ])
        ->and($logs[1]->event_type)->toBe(AdminEvent::UserDeactivated)
        ->and($logs[1]->old_values)->toBe(['is_active' => true])
        ->and($logs[1]->new_values)->toBe(['is_active' => false]);

    $raw = json_encode($logs->map->getAttributes());
    expect($raw)->not->toContain('password')
        ->and($raw)->not->toContain('token')
        ->and($raw)->not->toContain($this->target->fresh()->password);
});

test('a failure while auditing leaves no partial change', function () {
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));

    $this->withoutExceptionHandling();

    expect(fn () => patchUser($this->admin, $this->target, editData($this->target, ['name' => 'Parcial', 'status' => 'inactive'])))
        ->toThrow(RuntimeException::class, 'falha simulada');

    expect($this->target->fresh())
        ->name->toBe('Bruno Lima')
        ->is_active->toBeTrue();
});
