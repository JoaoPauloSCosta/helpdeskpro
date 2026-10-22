<?php

use App\Enums\AdminEvent;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
    $this->seededRoles = Role::count();
});

/** Dados válidos para o cadastro, com ajustes por teste. */
function newRoleData(array $overrides = []): array
{
    return [
        'name' => 'Supervisor de atendimento',
        'description' => 'Acompanha a fila e orienta os técnicos.',
        ...$overrides,
    ];
}

/** Papel personalizado só com a permissão informada. */
function roleCreatorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Só {$permission}", 'slug' => 'creator-'.str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/** Estado completo de papéis, associações e vínculos de usuários. */
function rbacSnapshot(): array
{
    return [
        Role::orderBy('id')->get(['id', 'name', 'slug', 'description', 'is_system', 'updated_at'])->toArray(),
        DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])->toArray(),
        User::orderBy('id')->pluck('role_id', 'id')->all(),
        Permission::count(),
    ];
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/roles/create')->assertRedirect('/login');
    $this->post('/admin/roles', newRoleData())->assertRedirect('/login');

    expect(Role::count())->toBe($this->seededRoles);
});

test('users without roles.manage can neither open the form nor create', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get('/admin/roles/create')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->missing('limits'));

    $this->actingAs($user)->post('/admin/roles', newRoleData())->assertForbidden();

    expect(Role::count())->toBe($this->seededRoles)
        ->and(AuditLog::count())->toBe(0);
})->with(['requester', 'technician', 'manager']);

test('other administrative permissions do not allow creating roles', function (string $permission) {
    $this->actingAs(roleCreatorWithOnly($permission))->post('/admin/roles', newRoleData())->assertForbidden();

    expect(Role::where('name', 'Supervisor de atendimento')->exists())->toBeFalse();
})->with(['users.manage', 'categories.manage', 'audit.view']);

test('access follows the permission, not the role name', function () {
    $this->actingAs(roleCreatorWithOnly('roles.manage'))->get('/admin/roles/create')->assertOk();

    Role::where('slug', 'admin')->first()->permissions()->detach();
    $this->actingAs($this->admin->fresh())->post('/admin/roles', newRoleData())->assertForbidden();
});

test('inactive administrators are denied', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())
        ->post('/admin/roles', newRoleData())
        ->assertForbidden();

    expect(Role::count())->toBe($this->seededRoles);
});

test('authorized users receive the form with the schema limits', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Roles/Create')
            ->where('limits', ['name' => 255, 'description' => 1000]));
});

/* ---------- Cadastro ---------- */

test('a valid role is persisted and the listing opens filtered on it', function () {
    $this->actingAs($this->admin)
        ->post('/admin/roles', newRoleData())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.roles.index', ['q' => 'Supervisor de atendimento']))
        ->assertSessionHas('success', 'Papel Supervisor de atendimento cadastrado sem permissões associadas.');

    $role = Role::where('slug', 'supervisor-de-atendimento')->firstOrFail();

    expect($role->name)->toBe('Supervisor de atendimento')
        ->and($role->description)->toBe('Acompanha a fila e orienta os técnicos.')
        ->and($role->is_system)->toBeFalse()
        ->and(Role::count())->toBe($this->seededRoles + 1);

    $this->actingAs($this->admin)
        ->get('/admin/roles?q=Supervisor de atendimento')
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles.data.0.name', 'Supervisor de atendimento')
            ->where('roles.data.0.permissions_count', 0)
            ->where('roles.data.0.users_count', 0));
});

test('the description is optional and blank text becomes null', function () {
    $this->actingAs($this->admin)
        ->post('/admin/roles', newRoleData(['name' => '  Auditor  ', 'description' => '   ']))
        ->assertSessionHasNoErrors();

    $role = Role::where('slug', 'auditor')->firstOrFail();

    expect($role->name)->toBe('Auditor')
        ->and($role->description)->toBeNull();
});

test('the new role receives no permissions and grants no access', function () {
    $this->actingAs($this->admin)->post('/admin/roles', newRoleData())->assertSessionHasNoErrors();

    $role = Role::where('slug', 'supervisor-de-atendimento')->firstOrFail();
    $member = User::factory()->create(['role_id' => $role->id]);

    expect($role->permissions()->count())->toBe(0)
        ->and($member->effectivePermissions())->toBe([]);

    $this->actingAs($member)->get('/admin/roles')->assertForbidden();
});

test('internal fields, permissions and users cannot be set by the request', function () {
    $other = User::factory()->withRole('requester')->create();

    $this->actingAs($this->admin)
        ->post('/admin/roles', newRoleData([
            'id' => 999,
            'slug' => 'admin',
            'is_system' => true,
            'permissions' => Permission::pluck('id')->all(),
            'permission_ids' => Permission::pluck('id')->all(),
            'users' => [$other->id],
            'created_at' => '2000-01-01 00:00:00',
        ]))
        ->assertSessionHasNoErrors();

    $role = Role::where('name', 'Supervisor de atendimento')->firstOrFail();

    expect($role->id)->not->toBe(999)
        ->and($role->slug)->toBe('supervisor-de-atendimento')
        ->and($role->is_system)->toBeFalse()
        ->and($role->created_at->year)->not->toBe(2000)
        ->and($role->permissions()->count())->toBe(0)
        ->and($other->fresh()->role->slug)->toBe('requester');
});

test('creating a role does not modify existing roles, permissions or user assignments', function () {
    $before = rbacSnapshot();
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)->post('/admin/roles', newRoleData())->assertSessionHasNoErrors();

    [$roles, $pivot, $users, $permissions] = rbacSnapshot();
    $newRoleId = Role::where('slug', 'supervisor-de-atendimento')->value('id');

    expect(collect($roles)->reject(fn ($role) => $role['id'] === $newRoleId)->values()->all())->toEqual($before[0])
        ->and($pivot)->toEqual($before[1])
        ->and($users)->toEqual($before[2])
        ->and($permissions)->toBe($before[3]);
});

/* ---------- Auditoria ---------- */

test('the creation is audited with the author, the role and no permissions', function () {
    $this->actingAs($this->admin)->post('/admin/roles', newRoleData())->assertSessionHasNoErrors();

    $role = Role::where('slug', 'supervisor-de-atendimento')->firstOrFail();
    $event = AuditLog::sole();

    expect($event->event_type)->toBe(AdminEvent::RoleCreated)
        ->and($event->auditable->is($role))->toBeTrue()
        ->and($event->user_id)->toBe($this->admin->id)
        ->and($event->old_values)->toBeNull()
        ->and($event->new_values)->toBe([
            'name' => 'Supervisor de atendimento',
            'slug' => 'supervisor-de-atendimento',
            'description' => 'Acompanha a fila e orienta os técnicos.',
            'is_system' => false,
            'permissions' => [],
        ]);
});

test('a rejected creation records nothing', function () {
    $this->actingAs($this->admin)->post('/admin/roles', newRoleData(['name' => '']))->assertSessionHasErrors('name');

    expect(Role::count())->toBe($this->seededRoles)
        ->and(AuditLog::count())->toBe(0);
});

/* ---------- Validação ---------- */

test('invalid data is rejected with field errors', function (array $data, string $field) {
    $this->actingAs($this->admin)
        ->from('/admin/roles/create')
        ->post('/admin/roles', newRoleData($data))
        ->assertRedirect('/admin/roles/create')
        ->assertSessionHasErrors($field);

    expect(Role::count())->toBe($this->seededRoles);
})->with([
    'missing name' => [['name' => null], 'name'],
    'blank name' => [['name' => '   '], 'name'],
    'name as array' => [['name' => ['x']], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'name without letters or digits' => [['name' => '!!! ---'], 'name'],
    'description too long' => [['description' => str_repeat('a', 1001)], 'description'],
    'description as array' => [['description' => ['x']], 'description'],
]);

test('the name limits match the schema', function () {
    $this->actingAs($this->admin)
        ->post('/admin/roles', newRoleData(['name' => str_repeat('a', 255), 'description' => str_repeat('b', 1000)]))
        ->assertSessionHasNoErrors();

    expect(Role::where('name', str_repeat('a', 255))->exists())->toBeTrue();
});

test('role names must be unique, ignoring case', function (string $name) {
    $this->actingAs($this->admin)
        ->post('/admin/roles', newRoleData(['name' => $name]))
        ->assertSessionHasErrors(['name' => 'Já existe um papel com este nome.']);

    expect(Role::count())->toBe($this->seededRoles);
})->with(['Gestor', 'gestor', 'TÉCNICO']);

test('names that produce an existing identifier are rejected', function () {
    $this->actingAs($this->admin)->post('/admin/roles', newRoleData(['name' => 'Supervisor']))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post('/admin/roles', newRoleData(['name' => 'Supervisor!']))
        ->assertSessionHasErrors(['name' => 'Já existe um papel com um nome equivalente.']);

    // O slug dos papéis do sistema também é protegido.
    $this->actingAs($this->admin)
        ->post('/admin/roles', newRoleData(['name' => 'Admin']))
        ->assertSessionHasErrors(['name' => 'Já existe um papel com um nome equivalente.']);

    expect(Role::count())->toBe($this->seededRoles + 1);
});

test('the database unique constraint is the final guard against concurrent duplicates', function () {
    // Simula outro envio gravado entre a validação e a inserção.
    Role::creating(function (Role $role) {
        if ($role->slug === 'supervisor-de-atendimento' && ! Role::where('slug', $role->slug)->exists()) {
            DB::table('roles')->insert(['name' => 'Concorrente', 'slug' => $role->slug, 'is_system' => false]);
        }
    });

    $this->actingAs($this->admin)
        ->post('/admin/roles', newRoleData())
        ->assertSessionHasErrors(['name' => 'Já existe um papel com um nome equivalente.']);

    // A transação desfeita não deixa o papel nem o evento de auditoria pela metade.
    expect(Role::where('name', 'Supervisor de atendimento')->exists())->toBeFalse()
        ->and(AuditLog::count())->toBe(0);
});

/* ---------- Listagem ---------- */

test('the listing offers the create action only to authorized users', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles')
        ->assertInertia(fn (Assert $page) => $page->where('can.create', true));

    $this->actingAs(roleCreatorWithOnly('roles.manage'))
        ->get('/admin/roles')
        ->assertInertia(fn (Assert $page) => $page->where('can.create', true));
});
