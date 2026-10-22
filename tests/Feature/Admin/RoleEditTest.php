<?php

use App\Enums\AdminEvent;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
    $this->role = Role::create([
        'name' => 'Supervisor',
        'slug' => 'supervisor',
        'description' => 'Acompanha a fila.',
    ]);
    $this->role->permissions()->attach(
        Permission::whereIn('slug', ['tickets.assign', 'audit.view'])->pluck('id'),
        ['created_at' => now()],
    );
    $this->members = User::factory()->count(2)->create(['role_id' => $this->role->id]);
});

/** Dados atuais do papel como o formulário os envia, com ajustes por teste. */
function roleEditData(Role $role, array $overrides = []): array
{
    $role->refresh();

    return [
        'name' => $role->name,
        'description' => $role->description,
        'version' => $role->editVersion(),
        ...$overrides,
    ];
}

function patchRole(User $operator, Role $role, array $data): TestResponse
{
    return test()->actingAs($operator)->patch("/admin/roles/{$role->id}", $data);
}

/** Papel personalizado só com a permissão informada. */
function roleEditorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Editor {$permission}", 'slug' => 'editor-'.str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/** Associações de permissões e vínculos de usuários de todos os papéis. */
function roleAssociations(): array
{
    return [
        DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id', 'created_at'])->toArray(),
        User::orderBy('id')->pluck('role_id', 'id')->all(),
    ];
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get("/admin/roles/{$this->role->id}/edit")->assertRedirect('/login');
    $this->patch("/admin/roles/{$this->role->id}", roleEditData($this->role, ['name' => 'X']))->assertRedirect('/login');

    expect($this->role->fresh()->name)->toBe('Supervisor');
});

test('users without roles.manage can neither view nor edit', function (string $slug) {
    $user = User::factory()->withRole($slug)->create();

    $this->actingAs($user)
        ->get("/admin/roles/{$this->role->id}/edit")
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->missing('role'));

    patchRole($user, $this->role, roleEditData($this->role, ['name' => 'Invadido']))->assertForbidden();

    expect($this->role->fresh()->name)->toBe('Supervisor')
        ->and(AuditLog::count())->toBe(0);
})->with(['requester', 'technician', 'manager']);

test('other administrative permissions do not allow editing roles', function (string $permission) {
    patchRole(roleEditorWithOnly($permission), $this->role, roleEditData($this->role, ['name' => 'Invadido']))->assertForbidden();

    expect($this->role->fresh()->name)->toBe('Supervisor');
})->with(['users.manage', 'categories.manage', 'audit.view']);

test('access follows the permission, not the role name', function () {
    $this->actingAs(roleEditorWithOnly('roles.manage'))->get("/admin/roles/{$this->role->id}/edit")->assertOk();

    Role::where('slug', 'admin')->first()->permissions()->detach();
    patchRole($this->admin->fresh(), $this->role, roleEditData($this->role, ['name' => 'Outro']))->assertForbidden();
});

test('inactive administrators are denied', function () {
    patchRole(User::factory()->withRole('admin')->inactive()->create(), $this->role, roleEditData($this->role, ['name' => 'Outro']))
        ->assertForbidden();

    expect($this->role->fresh()->name)->toBe('Supervisor');
});

test('unauthorized users cannot tell which identifiers exist', function () {
    $user = User::factory()->withRole('technician')->create();

    $this->actingAs($user)->get('/admin/roles/999999/edit')->assertForbidden();
    $this->actingAs($user)->patch('/admin/roles/999999', ['name' => 'X'])->assertForbidden();
});

test('missing roles return not found to authorized operators', function () {
    $this->actingAs($this->admin)->get('/admin/roles/999999/edit')->assertNotFound();
    $this->actingAs($this->admin)->patch('/admin/roles/999999', ['name' => 'X', 'version' => 'x'])->assertNotFound();
});

test('non numeric identifiers do not match the route', function () {
    $this->actingAs($this->admin)->get('/admin/roles/supervisor/edit')->assertNotFound();
});

/* ---------- Formulário ---------- */

test('the form receives the real data of the selected role', function () {
    $this->actingAs($this->admin)
        ->get("/admin/roles/{$this->role->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Roles/Edit')
            ->where('role.id', $this->role->id)
            ->where('role.name', 'Supervisor')
            ->where('role.slug', 'supervisor')
            ->where('role.is_system', false)
            ->where('role.users_count', 2)
            ->where('role.permissions_count', 2)
            ->where('values', ['name' => 'Supervisor', 'description' => 'Acompanha a fila.'])
            ->where('limits', ['name' => 255, 'description' => 1000])
            ->where('version', $this->role->editVersion()));
});

test('a role without description loads an empty field', function () {
    $this->role->forceFill(['description' => null])->save();

    $this->actingAs($this->admin)
        ->get("/admin/roles/{$this->role->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('values.description', ''));
});

test('the listing offers the edit action to authorized operators', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles?q=supervisor')
        ->assertInertia(fn (Assert $page) => $page->where('roles.data.0.can_edit', true));
});

/* ---------- Gravação ---------- */

test('valid changes are persisted and the operator returns to the listing', function () {
    patchRole($this->admin, $this->role, roleEditData($this->role, [
        'name' => 'Supervisor de atendimento',
        'description' => 'Coordena a fila e orienta os técnicos.',
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.roles.index', ['q' => 'Supervisor de atendimento']))
        ->assertSessionHas('success', 'Alterações do papel Supervisor de atendimento salvas. Permissões e usuários vinculados foram mantidos.');

    $role = $this->role->fresh();

    expect($role->name)->toBe('Supervisor de atendimento')
        ->and($role->description)->toBe('Coordena a fila e orienta os técnicos.')
        // O identificador interno é estável.
        ->and($role->slug)->toBe('supervisor');
});

test('the description can be changed alone or cleared', function () {
    patchRole($this->admin, $this->role, roleEditData($this->role, ['description' => 'Nova descrição.']))->assertSessionHasNoErrors();
    expect($this->role->fresh()->description)->toBe('Nova descrição.');

    patchRole($this->admin, $this->role, roleEditData($this->role, ['description' => '   ']))->assertSessionHasNoErrors();
    expect($this->role->fresh()->description)->toBeNull();
});

test('the role may keep its own name, in any case variation of the check', function () {
    patchRole($this->admin, $this->role, roleEditData($this->role, ['description' => 'Outra.']))->assertSessionHasNoErrors();
    patchRole($this->admin, $this->role, roleEditData($this->role, ['name' => 'SUPERVISOR']))->assertSessionHasNoErrors();

    expect($this->role->fresh()->name)->toBe('SUPERVISOR');
});

test('saving without changes records nothing', function () {
    patchRole($this->admin, $this->role, roleEditData($this->role))
        ->assertRedirect(route('admin.roles.edit', $this->role))
        ->assertSessionHas('info', 'Nenhuma alteração para salvar.');

    expect(AuditLog::count())->toBe(0);
});

test('a stale form is rejected as a conflict', function () {
    $stale = roleEditData($this->role, ['name' => 'Versão antiga']);
    $this->role->forceFill(['description' => 'Alterada por outra pessoa.'])->save();

    patchRole($this->admin, $this->role, $stale)->assertSessionHasErrors('conflict');

    expect($this->role->fresh()->name)->toBe('Supervisor')
        ->and(AuditLog::count())->toBe(0);
});

/* ---------- Validação ---------- */

test('invalid values are rejected without partial changes', function (array $overrides, string $field) {
    patchRole($this->admin, $this->role, roleEditData($this->role, ['description' => 'Mudaria.', ...$overrides]))
        ->assertSessionHasErrors($field);

    expect($this->role->fresh()->only(['name', 'description']))->toBe(['name' => 'Supervisor', 'description' => 'Acompanha a fila.'])
        ->and(AuditLog::count())->toBe(0);
})->with([
    'missing name' => [['name' => null], 'name'],
    'blank name' => [['name' => '   '], 'name'],
    'name as array' => [['name' => ['x']], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'name without letters or digits' => [['name' => '!!!'], 'name'],
    'description too long' => [['description' => str_repeat('a', 1001)], 'description'],
    'missing version' => [['version' => null], 'version'],
]);

test('names already used by another role are rejected', function (string $name, string $message) {
    patchRole($this->admin, $this->role, roleEditData($this->role, ['name' => $name]))
        ->assertSessionHasErrors(['name' => $message]);

    expect($this->role->fresh()->name)->toBe('Supervisor');
})->with([
    ['Gestor', 'Já existe um papel com este nome.'],
    ['técnico', 'Já existe um papel com este nome.'],
    ['Gestor!', 'Já existe um papel com um nome equivalente.'],
    // Colide com o identificador interno de um papel do sistema.
    ['Admin', 'Já existe um papel com um nome equivalente.'],
]);

test('the limits accept values at the boundary', function () {
    patchRole($this->admin, $this->role, roleEditData($this->role, ['name' => str_repeat('a', 255), 'description' => str_repeat('b', 1000)]))
        ->assertSessionHasNoErrors();

    expect(mb_strlen($this->role->fresh()->name))->toBe(255);
});

/* ---------- Campos protegidos e vínculos ---------- */

test('fields outside the form are not changed by manipulated requests', function () {
    $before = roleAssociations();
    $other = User::factory()->withRole('requester')->create();

    patchRole($this->admin, $this->role, roleEditData($this->role, [
        'name' => 'Supervisor geral',
        'id' => 999,
        'slug' => 'admin',
        'is_system' => true,
        'permissions' => Permission::pluck('id')->all(),
        'users' => [$other->id],
        'created_at' => '2000-01-01 00:00:00',
    ]))->assertSessionHasNoErrors();

    $role = Role::find($this->role->id);

    expect($role->name)->toBe('Supervisor geral')
        ->and($role->slug)->toBe('supervisor')
        ->and($role->is_system)->toBeFalse()
        ->and($role->created_at->year)->not->toBe(2000)
        ->and(Role::find(999))->toBeNull()
        ->and($other->fresh()->role->slug)->toBe('requester')
        ->and(roleAssociations()[0])->toEqual($before[0]);
});

test('permissions and user assignments are preserved by the edition', function () {
    $before = roleAssociations();
    $memberPermissions = $this->members->first()->effectivePermissions();

    patchRole($this->admin, $this->role, roleEditData($this->role, ['name' => 'Coordenação', 'description' => null]))
        ->assertSessionHasNoErrors();

    $member = $this->members->first()->fresh();

    expect(roleAssociations())->toEqual($before)
        ->and($member->role_id)->toBe($this->role->id)
        ->and($member->effectivePermissions())->toEqualCanonicalizing($memberPermissions)
        ->and($this->role->fresh()->permissions()->pluck('slug')->sort()->values()->all())->toBe(['audit.view', 'tickets.assign']);
});

test('renaming does not grant any access by name', function () {
    $requester = User::factory()->withRole('requester')->create();
    $requesterRole = $requester->role;

    // Um papel personalizado sem permissões renomeado para algo que soa privilegiado continua sem acesso.
    $custom = Role::create(['name' => 'Visitante', 'slug' => 'visitante']);
    $guest = User::factory()->create(['role_id' => $custom->id]);

    patchRole($this->admin, $custom, roleEditData($custom, ['name' => 'Super administrador']))->assertSessionHasNoErrors();

    expect($guest->fresh()->effectivePermissions())->toBe([])
        ->and($requesterRole->fresh()->permissions()->count())->toBe(0);

    $this->actingAs($guest->fresh())->get('/admin/roles')->assertForbidden();
});

test('editing one role does not change the others', function () {
    $others = fn () => Role::whereKeyNot($this->role->id)->orderBy('id')->get(['id', 'name', 'slug', 'description', 'is_system', 'updated_at'])->toArray();
    $before = $others();
    $this->travel(5)->minutes();

    patchRole($this->admin, $this->role, roleEditData($this->role, ['name' => 'Outro nome']))->assertSessionHasNoErrors();

    expect($others())->toEqual($before);
});

/* ---------- Papéis do sistema ---------- */

test('system roles keep their name but accept a new description', function (string $slug) {
    $role = Role::where('slug', $slug)->first();
    $name = $role->name;

    patchRole($this->admin, $role, roleEditData($role, ['name' => 'Renomeado']))
        ->assertSessionHasErrors(['name' => 'O nome dos papéis do sistema não pode ser alterado.']);

    expect($role->fresh()->name)->toBe($name);

    patchRole($this->admin, $role, roleEditData($role, ['description' => 'Perfil de referência do PRD.']))
        ->assertSessionHasNoErrors();

    expect($role->fresh()->only(['name', 'description', 'is_system']))
        ->toBe(['name' => $name, 'description' => 'Perfil de referência do PRD.', 'is_system' => true]);
})->with(['admin', 'manager', 'technician', 'requester']);

test('system roles can be opened and flag the protection', function () {
    $admin = Role::where('slug', 'admin')->first();

    $this->actingAs($this->admin)
        ->get("/admin/roles/{$admin->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('role.is_system', true)->where('values.name', 'Administrador'));
});

/* ---------- Auditoria ---------- */

test('changes are audited with the operator and only the changed fields', function () {
    patchRole($this->admin, $this->role, roleEditData($this->role, ['name' => 'Coordenação']))->assertSessionHasNoErrors();

    $event = AuditLog::sole();

    expect($event->event_type)->toBe(AdminEvent::RoleUpdated)
        ->and($event->auditable->is($this->role))->toBeTrue()
        ->and($event->user_id)->toBe($this->admin->id)
        ->and($event->old_values)->toBe(['name' => 'Supervisor'])
        ->and($event->new_values)->toBe(['name' => 'Coordenação']);
});

test('a failure while auditing leaves no partial change', function () {
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));

    $this->withoutExceptionHandling();

    expect(fn () => patchRole($this->admin, $this->role, roleEditData($this->role, ['name' => 'Coordenação'])))
        ->toThrow(RuntimeException::class);

    expect($this->role->fresh()->name)->toBe('Supervisor');
});
