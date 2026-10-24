<?php

use App\Enums\AdminEvent;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvitation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create(['email' => 'admin@example.com']);
    $this->department = Department::factory()->create(['name' => 'Suporte']);
    Notification::fake();
});

function roleId(string $slug): int
{
    return Role::where('slug', $slug)->value('id');
}

/** Dados válidos para a criação, com ajustes por teste. */
function newUserData(array $overrides = []): array
{
    return [
        'name' => 'Maria Oliveira',
        'email' => 'maria@example.com',
        'role_id' => roleId('technician'),
        'department_id' => Department::where('name', 'Suporte')->value('id'),
        'status' => 'active',
        ...$overrides,
    ];
}

/** Papel personalizado só com `users.manage`, sem as demais permissões administrativas. */
function userManagerWithoutAdminRights(): User
{
    $role = Role::create(['name' => 'Recursos Humanos', 'slug' => 'hr']);
    $role->permissions()->attach(Permission::where('slug', 'users.manage')->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/users/create')->assertRedirect('/login');
    $this->post('/admin/users', newUserData())->assertRedirect('/login');

    expect(User::where('email', 'maria@example.com')->exists())->toBeFalse();
});

test('users without users.manage cannot open the form nor create users', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get('/admin/users/create')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->missing('roles'));

    $this->actingAs($user)->post('/admin/users', newUserData())->assertForbidden();

    expect(User::where('email', 'maria@example.com')->exists())->toBeFalse();
})->with(['requester', 'technician', 'manager']);

test('inactive administrators cannot create users', function () {
    $inactive = User::factory()->withRole('admin')->inactive()->create();

    $this->actingAs($inactive)->get('/admin/users/create')->assertForbidden();
    $this->actingAs($inactive)->post('/admin/users', newUserData())->assertForbidden();

    expect(User::where('email', 'maria@example.com')->exists())->toBeFalse();
});

test('the form receives real roles, active departments and no sensitive data', function () {
    Department::factory()->create(['name' => 'Antigo', 'is_active' => false]);

    $response = $this->actingAs($this->admin)
        ->get('/admin/users/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Create')
            ->has('roles', 4)
            ->where('departments', [['value' => (string) $this->department->id, 'label' => 'Suporte']])
            ->where('statuses.0.value', 'active')
            ->where('limits', ['name' => 255, 'email' => 255]));

    $props = json_encode($response->viewData('page')['props']);
    expect($props)->not->toContain($this->admin->password)
        ->and($props)->not->toContain('remember_token')
        ->and($props)->not->toContain('"password"');
});

/* ---------- Criação ---------- */

test('authorized users create a user and are redirected to the listing', function () {
    $this->actingAs($this->admin)
        ->post('/admin/users', newUserData())
        ->assertRedirect(route('admin.users.index', ['q' => 'maria@example.com']))
        ->assertSessionHas('success', 'Usuário Maria Oliveira criado. Enviamos um e-mail para maria@example.com com o link para definir a senha.')
        ->assertSessionHasNoErrors();

    $user = User::where('email', 'maria@example.com')->firstOrFail();

    expect($user->name)->toBe('Maria Oliveira')
        ->and($user->role_id)->toBe(roleId('technician'))
        ->and($user->department_id)->toBe($this->department->id)
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->toBeNull();

    // A criação administrativa não autentica o novo usuário.
    $this->assertAuthenticatedAs($this->admin);

    // A listagem de destino existe e mostra o usuário criado.
    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['q' => 'maria@example.com']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('users.data.0.email', 'maria@example.com'));
});

test('the e-mail is normalized to lowercase', function () {
    $this->actingAs($this->admin)->post('/admin/users', newUserData(['email' => '  Maria@Example.COM ']));

    expect(User::where('email', 'maria@example.com')->exists())->toBeTrue();
});

test('the department is optional', function () {
    $this->actingAs($this->admin)
        ->post('/admin/users', newUserData(['department_id' => null]))
        ->assertSessionHasNoErrors();

    expect(User::where('email', 'maria@example.com')->value('department_id'))->toBeNull();
});

test('the initial password is a random hash that nobody chose', function () {
    $this->actingAs($this->admin)
        ->post('/admin/users', newUserData(['password' => 'escolhida123', 'password_confirmation' => 'escolhida123']))
        ->assertSessionHasNoErrors();

    $user = User::where('email', 'maria@example.com')->firstOrFail();

    expect(Hash::isHashed($user->password))->toBeTrue()
        ->and(Hash::check('escolhida123', $user->password))->toBeFalse()
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

test('fields outside the form are ignored', function () {
    $this->actingAs($this->admin)
        ->post('/admin/users', newUserData([
            'email_verified_at' => now()->toDateTimeString(),
            'remember_token' => 'token-forjado',
            'is_active' => false,
            'id' => 9999,
        ]))
        ->assertSessionHasNoErrors();

    $user = User::where('email', 'maria@example.com')->firstOrFail();

    expect($user->id)->not->toBe(9999)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->remember_token)->not->toBe('token-forjado')
        ->and($user->is_active)->toBeTrue();
});

/* ---------- Convite (fluxo de redefinição de senha do Breeze) ---------- */

test('active users receive an invitation that uses the password reset flow', function () {
    $this->actingAs($this->admin)->post('/admin/users', newUserData());

    $user = User::where('email', 'maria@example.com')->firstOrFail();

    Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use ($user) {
        $mail = $notification->toMail($user);

        return Password::broker()->tokenExists($user, $notification->token)
            && str_contains($mail->actionUrl, route('password.reset', $notification->token, false))
            && $mail->actionText === 'Definir senha';
    });
    Notification::assertCount(1);
});

test('the invitation token lets the user set the password and log in', function () {
    $this->actingAs($this->admin)->post('/admin/users', newUserData());
    $user = User::where('email', 'maria@example.com')->firstOrFail();
    $token = null;
    Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    auth()->logout();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'maria@example.com',
        'password' => 'NovaSenha#2026',
        'password_confirmation' => 'NovaSenha#2026',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check('NovaSenha#2026', $user->fresh()->password))->toBeTrue();
});

test('inactive users are created without an invitation', function () {
    $this->actingAs($this->admin)
        ->post('/admin/users', newUserData(['status' => 'inactive']))
        ->assertSessionHas('success', 'Usuário Maria Oliveira criado como inativo. Nenhum convite foi enviado.');

    $user = User::where('email', 'maria@example.com')->firstOrFail();

    expect($user->is_active)->toBeFalse();
    Notification::assertNothingSent();

    // Inativo, não executa operações protegidas (RF-02).
    $this->actingAs($user)->get('/tickets')->assertForbidden();
});

test('a failed invitation keeps the user and warns the operator', function () {
    $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('SMTP indisponível')));

    $this->actingAs($this->admin)
        ->post('/admin/users', newUserData())
        ->assertRedirect(route('admin.users.index', ['q' => 'maria@example.com']))
        ->assertSessionHas('success', 'Usuário Maria Oliveira criado.')
        ->assertSessionHas('warning');

    expect(User::where('email', 'maria@example.com')->exists())->toBeTrue();
});

/* ---------- Validação ---------- */

test('name, e-mail, role and status are required', function () {
    $this->actingAs($this->admin)
        ->post('/admin/users', ['name' => '', 'email' => '', 'role_id' => '', 'status' => ''])
        ->assertSessionHasErrors(['name', 'email', 'role_id', 'status']);

    expect(User::count())->toBe(1);
});

test('invalid values are rejected', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->post('/admin/users', newUserData($overrides))
        ->assertSessionHasErrors($field);

    expect(User::where('email', 'maria@example.com')->exists())->toBeFalse();
})->with([
    'invalid e-mail' => [['email' => 'nao-e-um-email'], 'email'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'e-mail too long' => [['email' => str_repeat('a', 250).'@x.com'], 'email'],
    'missing role' => [['role_id' => 999999], 'role_id'],
    'non numeric role' => [['role_id' => 'admin'], 'role_id'],
    'role as array' => [['role_id' => [1]], 'role_id'],
    'missing department' => [['department_id' => 999999], 'department_id'],
    'unknown status' => [['status' => 'suspended'], 'status'],
]);

test('inactive departments are rejected for new users', function () {
    $inactive = Department::factory()->create(['is_active' => false]);

    $this->actingAs($this->admin)
        ->post('/admin/users', newUserData(['department_id' => $inactive->id]))
        ->assertSessionHasErrors(['department_id' => 'Selecione um departamento ativo.']);
});

test('duplicate e-mails are rejected without revealing the existing account', function () {
    User::factory()->withRole('manager')->create(['name' => 'Conta Existente', 'email' => 'maria@example.com']);

    $response = $this->actingAs($this->admin)
        ->post('/admin/users', newUserData(['email' => 'MARIA@example.com']))
        ->assertSessionHasErrors(['email' => 'Já existe um usuário cadastrado com este e-mail.']);

    expect(session('errors')->first('email'))->not->toContain('Conta Existente')
        ->and(User::where('email', 'maria@example.com')->count())->toBe(1);
});

/* ---------- Elevação de privilégios ---------- */

test('operators cannot grant roles with administrative permissions they lack', function () {
    $operator = userManagerWithoutAdminRights();

    $this->actingAs($operator)
        ->post('/admin/users', newUserData(['role_id' => roleId('admin')]))
        ->assertSessionHasErrors(['role_id' => 'Você não tem permissão para atribuir este papel.']);

    // Gestor tem `audit.view`, que o operador não possui.
    $this->actingAs($operator)
        ->post('/admin/users', newUserData(['role_id' => roleId('manager')]))
        ->assertSessionHasErrors('role_id');

    expect(User::where('email', 'maria@example.com')->exists())->toBeFalse();

    // O Técnico tem `audit.view` (RF-19, v1.8), que o operador não possui.
    $this->actingAs($operator)
        ->post('/admin/users', newUserData(['role_id' => roleId('technician')]))
        ->assertSessionHasErrors('role_id');

    // Papéis só com permissões operacionais continuam disponíveis.
    $this->actingAs($operator)
        ->post('/admin/users', newUserData(['role_id' => roleId('requester')]))
        ->assertSessionHasNoErrors();
});

test('the form only offers roles the operator can grant', function () {
    $this->actingAs(userManagerWithoutAdminRights())
        ->get('/admin/users/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where(
            'roles',
            fn ($roles) => collect($roles)->pluck('label')->sort()->values()->all() === ['Recursos Humanos', 'Solicitante'],
        ));
});

test('administrators can grant every initial role', function (string $slug) {
    $this->actingAs($this->admin)
        ->post('/admin/users', newUserData(['role_id' => roleId($slug)]))
        ->assertSessionHasNoErrors();
})->with(['requester', 'technician', 'manager', 'admin']);

/* ---------- Auditoria e atomicidade ---------- */

test('the creation is audited with the operator and without secrets', function () {
    $this->actingAs($this->admin)->post('/admin/users', newUserData());

    $user = User::where('email', 'maria@example.com')->firstOrFail();
    $log = AuditLog::sole();

    expect($log->event_type)->toBe(AdminEvent::UserCreated)
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->auditable->is($user))->toBeTrue()
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBe([
            'name' => 'Maria Oliveira',
            'email' => 'maria@example.com',
            'role' => ['id' => roleId('technician'), 'label' => 'Técnico'],
            'department' => ['id' => $this->department->id, 'label' => 'Suporte'],
            'is_active' => true,
        ]);

    $raw = json_encode($log->getAttributes());
    expect($raw)->not->toContain($user->password)
        ->and($raw)->not->toContain('password')
        ->and($raw)->not->toContain('token');
});

test('a failure while auditing leaves no partial record', function () {
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->admin)->post('/admin/users', newUserData()))
        ->toThrow(RuntimeException::class, 'falha simulada');

    expect(User::where('email', 'maria@example.com')->exists())->toBeFalse()
        ->and(AuditLog::count())->toBe(0);
    Notification::assertNothingSent();
});

/* ---------- Cadastro público ---------- */

test('public registration cannot choose a role or status, only the department link', function () {
    auth()->logout();

    $this->post('/register', [
        'name' => 'Visitante',
        'email' => 'visitante@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role_id' => roleId('admin'),
        'department_id' => $this->department->id,
        'is_active' => false,
    ]);

    $user = User::where('email', 'visitante@example.com')->firstOrFail();

    expect($user->role_id)->toBe(roleId('requester'))
        ->and($user->department_id)->toBe($this->department->id)
        ->and($user->is_active)->toBeTrue()
        ->and($user->effectivePermissions())->toBe([])
        ->and(AuditLog::count())->toBe(0);
});
