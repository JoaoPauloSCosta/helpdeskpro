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
    $this->technician = Role::where('slug', 'technician')->first();
});

/** Permissões persistidas do papel, ordenadas. */
function roleSlugs(Role $role): array
{
    return $role->permissions()->pluck('slug')->sort()->values()->all();
}

/** Envio da matriz: conjunto completo desejado e versão atual, com ajustes por teste. */
function matrixData(Role $role, array $permissions, array $overrides = []): array
{
    return ['permissions' => $permissions, 'version' => $role->fresh()->permissionsVersion(), ...$overrides];
}

function putMatrix(User $operator, Role $role, array $data): TestResponse
{
    return test()->actingAs($operator)->put("/admin/roles/{$role->id}/permissions", $data);
}

/** Papel personalizado com as permissões informadas e um usuário nele. */
function matrixOperatorWith(array $permissions): User
{
    $role = Role::create(['name' => 'Operador '.implode(' ', $permissions), 'slug' => 'op-'.md5(implode(',', $permissions))]);
    $role->permissions()->attach(Permission::whereIn('slug', $permissions)->pluck('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/** Todas as associações, com a data de cada uma. */
function pivotSnapshot(): array
{
    return DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all();
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/roles/permissions')->assertRedirect('/login');
    $this->put("/admin/roles/{$this->technician->id}/permissions", ['permissions' => []])->assertRedirect('/login');

    expect(roleSlugs($this->technician))->toHaveCount(7);
});

test('users without roles.manage can neither view nor change the matrix', function (string $slug) {
    $user = User::factory()->withRole($slug)->create();
    $before = pivotSnapshot();

    $this->actingAs($user)
        ->get("/admin/roles/permissions?role={$this->technician->id}")
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->missing('catalog')->missing('selected'));

    putMatrix($user, $this->technician, matrixData($this->technician, []))->assertForbidden();

    expect(pivotSnapshot())->toBe($before)
        ->and(AuditLog::count())->toBe(0);
})->with(['requester', 'technician', 'manager']);

test('other administrative permissions do not grant access to the matrix', function (string $permission) {
    $user = matrixOperatorWith([$permission]);

    $this->actingAs($user)->get('/admin/roles/permissions')->assertForbidden();
    putMatrix($user, $this->technician, matrixData($this->technician, []))->assertForbidden();
})->with(['users.manage', 'categories.manage', 'audit.view']);

test('inactive administrators are denied', function () {
    $inactive = User::factory()->withRole('admin')->inactive()->create();

    $this->actingAs($inactive)->get('/admin/roles/permissions')->assertForbidden();
    putMatrix($inactive, $this->technician, matrixData($this->technician, []))->assertForbidden();
});

test('unauthorized users cannot tell which identifiers exist', function () {
    $user = User::factory()->withRole('technician')->create();

    $this->actingAs($user)->get('/admin/roles/permissions?role=999999')->assertForbidden();
    $this->actingAs($user)->put('/admin/roles/999999/permissions', ['permissions' => []])->assertForbidden();
});

test('missing roles return not found to authorized operators', function () {
    $this->actingAs($this->admin)->get('/admin/roles/permissions?role=999999')->assertNotFound();
    $this->actingAs($this->admin)->put('/admin/roles/999999/permissions', ['permissions' => [], 'version' => 'x'])->assertNotFound();
});

test('an invalid role parameter is rejected', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles/permissions?role=abc')
        ->assertSessionHasErrors('role');
});

/* ---------- Consulta ---------- */

test('no role is selected automatically', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles/permissions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Roles/Permissions')
            ->where('selected', null)
            ->has('roles', 4)
            ->where('roles.0', ['id' => Role::where('slug', 'admin')->value('id'), 'name' => 'Administrador', 'is_system' => true, 'permissions_count' => 10]));
});

test('the catalog matches the real permissions, grouped by module', function () {
    $response = $this->actingAs($this->admin)->get('/admin/roles/permissions')->assertOk();
    $catalog = collect($response->viewData('page')['props']['catalog']);

    expect($catalog->pluck('key')->all())->toBe(['tickets', 'comments', 'audit', 'users', 'departments', 'categories', 'roles', 'sla'])
        ->and($catalog->flatMap(fn ($group) => collect($group['permissions'])->pluck('slug'))->sort()->values()->all())
        ->toBe(Permission::orderBy('slug')->pluck('slug')->all())
        ->and($catalog->firstWhere('key', 'tickets')['label'])->toBe('Chamados');

    $usersManage = collect($catalog->firstWhere('key', 'users')['permissions'])->firstWhere('slug', 'users.manage');

    expect($usersManage)->toBe([
        'slug' => 'users.manage',
        'name' => 'Gerenciar usuários',
        'description' => null,
        'administrative' => true,
        'grantable' => true,
    ]);
});

test('permissions outside the known modules are listed under other', function () {
    Permission::create(['name' => 'Exportar relatórios', 'slug' => 'reports.export']);

    $catalog = collect($this->actingAs($this->admin)->get('/admin/roles/permissions')->viewData('page')['props']['catalog']);

    expect($catalog->last()['key'])->toBe('other')
        ->and($catalog->last()['label'])->toBe('Outras')
        ->and($catalog->last()['permissions'][0]['slug'])->toBe('reports.export');
});

test('the selected role shows exactly its persisted permissions', function () {
    $expected = collect(RolesAndPermissionsSeeder::ROLES['technician'][1])->sort()->values()->all();

    $this->actingAs($this->admin)
        ->get("/admin/roles/permissions?role={$this->technician->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.id', $this->technician->id)
            ->where('selected.name', 'Técnico')
            ->where('selected.permissions', $expected)
            ->where('selected.version', $this->technician->permissionsVersion())
            ->where('selected.can_manage', true)
            ->where('selected.read_only_reason', null));
});

test('a role without permissions is shown as such', function () {
    $requester = Role::where('slug', 'requester')->first();

    $this->actingAs($this->admin)
        ->get("/admin/roles/permissions?role={$requester->id}")
        ->assertInertia(fn (Assert $page) => $page->where('selected.permissions', [])->where('selected.can_manage', true));
});

test('viewing the matrix changes nothing', function () {
    $before = pivotSnapshot();

    $this->actingAs($this->admin)->get("/admin/roles/permissions?role={$this->technician->id}")->assertOk();

    expect(pivotSnapshot())->toBe($before)
        ->and(AuditLog::count())->toBe(0);
});

/* ---------- Gravação ---------- */

test('permissions can be added and removed in one save', function () {
    $desired = [...array_diff(roleSlugs($this->technician), ['tickets.resolve']), 'tickets.close'];

    putMatrix($this->admin, $this->technician, matrixData($this->technician, $desired))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.roles.permissions.edit', ['role' => $this->technician->id]))
        ->assertSessionHas('success', 'Permissões do papel Técnico salvas: 1 adicionada e 1 removida.');

    expect(roleSlugs($this->technician))->toContain('tickets.close')
        ->not->toContain('tickets.resolve')
        ->toHaveCount(7);
});

test('the change takes effect on the users of the role', function () {
    $member = User::factory()->withRole('technician')->create();
    expect($member->hasPermission('tickets.close'))->toBeFalse();

    putMatrix($this->admin, $this->technician, matrixData($this->technician, [...roleSlugs($this->technician), 'tickets.close']))
        ->assertSessionHasNoErrors();

    expect($member->fresh()->hasPermission('tickets.close'))->toBeTrue()
        ->and($member->fresh()->role_id)->toBe($this->technician->id);
});

test('an empty list removes every permission of the role', function () {
    putMatrix($this->admin, $this->technician, matrixData($this->technician, []))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Permissões do papel Técnico salvas: 0 adicionadas e 7 removidas.');

    expect(roleSlugs($this->technician))->toBe([])
        ->and(Permission::count())->toBe(19);
});

test('unchanged associations are kept as they were', function () {
    $keptBefore = DB::table('role_permissions')->where('role_id', $this->technician->id)->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all();
    $this->travel(10)->minutes();

    putMatrix($this->admin, $this->technician, matrixData($this->technician, [...roleSlugs($this->technician), 'tickets.close']))
        ->assertSessionHasNoErrors();

    $closeId = Permission::where('slug', 'tickets.close')->value('id');
    $keptAfter = DB::table('role_permissions')->where('role_id', $this->technician->id)->where('permission_id', '!=', $closeId)
        ->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all();

    expect($keptAfter)->toBe($keptBefore);
});

test('other roles, the catalog and user assignments stay intact', function () {
    $others = DB::table('role_permissions')->where('role_id', '!=', $this->technician->id)->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all();
    $catalog = Permission::orderBy('id')->get()->toArray();
    $users = User::orderBy('id')->pluck('role_id', 'id')->all();

    putMatrix($this->admin, $this->technician, matrixData($this->technician, ['tickets.close']))->assertSessionHasNoErrors();

    expect(DB::table('role_permissions')->where('role_id', '!=', $this->technician->id)->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all())->toBe($others)
        ->and(Permission::orderBy('id')->get()->toArray())->toBe($catalog)
        ->and(User::orderBy('id')->pluck('role_id', 'id')->all())->toBe($users);
});

test('no duplicate associations are created', function () {
    putMatrix($this->admin, $this->technician, matrixData($this->technician, [...roleSlugs($this->technician), 'tickets.close']))
        ->assertSessionHasNoErrors();
    putMatrix($this->admin, $this->technician, matrixData($this->technician, roleSlugs($this->technician)))
        ->assertSessionHas('info', 'Nenhuma alteração para salvar.');

    $pairs = DB::table('role_permissions')->get(['role_id', 'permission_id'])->map(fn ($row) => "{$row->role_id}-{$row->permission_id}");

    expect($pairs->count())->toBe($pairs->unique()->count())
        ->and(roleSlugs($this->technician))->toHaveCount(8);
});

test('saving without changes records nothing', function () {
    putMatrix($this->admin, $this->technician, matrixData($this->technician, roleSlugs($this->technician)))
        ->assertRedirect(route('admin.roles.permissions.edit', ['role' => $this->technician->id]))
        ->assertSessionHas('info', 'Nenhuma alteração para salvar.');

    expect(AuditLog::count())->toBe(0);
});

test('a stale matrix is rejected as a conflict', function () {
    $stale = matrixData($this->technician, [...roleSlugs($this->technician), 'tickets.close']);
    $this->technician->permissions()->detach(Permission::where('slug', 'tickets.resolve')->value('id'));

    putMatrix($this->admin, $this->technician, $stale)->assertSessionHasErrors('conflict');

    expect(roleSlugs($this->technician))->not->toContain('tickets.close')
        ->and(AuditLog::count())->toBe(0);
});

/* ---------- Validação ---------- */

test('invalid payloads are rejected without changes', function (array $data, string $field) {
    $before = pivotSnapshot();

    putMatrix($this->admin, $this->technician, [...matrixData($this->technician, ['tickets.close']), ...$data])
        ->assertSessionHasErrors($field);

    expect(pivotSnapshot())->toBe($before);
})->with([
    'unknown permission' => [['permissions' => ['tickets.close', 'tickets.delete_everything']], 'permissions.1'],
    'numeric identifier' => [['permissions' => [1]], 'permissions.0'],
    'duplicated permission' => [['permissions' => ['tickets.close', 'tickets.close']], 'permissions.0'],
    'not a list' => [['permissions' => 'tickets.close'], 'permissions'],
    'missing list' => [['permissions' => null], 'permissions'],
    'missing version' => [['version' => null], 'version'],
]);

test('extra fields cannot change the role or other roles', function () {
    $admin = Role::where('slug', 'admin')->first();

    putMatrix($this->admin, $this->technician, matrixData($this->technician, ['tickets.close'], [
        'role_id' => $admin->id,
        'name' => 'Renomeado',
        'is_system' => false,
        'users' => [$this->admin->id],
    ]))->assertSessionHasNoErrors();

    expect(roleSlugs($this->technician))->toBe(['tickets.close'])
        ->and($this->technician->fresh()->only(['name', 'is_system']))->toBe(['name' => 'Técnico', 'is_system' => true])
        ->and(roleSlugs($admin))->toHaveCount(10);
});

/* ---------- Escalada de privilégios ---------- */

test('operators cannot change their own role', function () {
    $adminRole = Role::where('slug', 'admin')->first();

    $this->actingAs($this->admin)
        ->get("/admin/roles/permissions?role={$adminRole->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selected.can_manage', false)->where('selected.read_only_reason', 'own_role'));

    putMatrix($this->admin, $adminRole, matrixData($adminRole, [...roleSlugs($adminRole), 'tickets.close']))->assertForbidden();
    putMatrix($this->admin, $adminRole, matrixData($adminRole, []))->assertForbidden();

    expect(roleSlugs($adminRole))->toHaveCount(10);
});

test('administrative permissions the operator lacks cannot be granted', function () {
    $operator = matrixOperatorWith(['roles.manage']);

    putMatrix($operator, $this->technician, matrixData($this->technician, [...roleSlugs($this->technician), 'users.manage', 'sla.manage']))
        ->assertSessionHasErrors(['permissions' => 'Você só pode conceder ou retirar permissões administrativas que também possui: Gerenciar políticas de SLA, Gerenciar usuários.']);

    expect(roleSlugs($this->technician))->not->toContain('users.manage')
        ->and(AuditLog::count())->toBe(0);
});

test('operational permissions can be granted without holding them', function () {
    // O Administrador não possui `tickets.close` (seção 7.4), mas configura a matriz dos técnicos.
    expect($this->admin->hasPermission('tickets.close'))->toBeFalse();

    putMatrix($this->admin, $this->technician, matrixData($this->technician, [...roleSlugs($this->technician), 'tickets.close']))
        ->assertSessionHasNoErrors();

    expect(roleSlugs($this->technician))->toContain('tickets.close')
        ->and($this->admin->fresh()->hasPermission('tickets.close'))->toBeFalse();
});

test('administrative permissions the operator holds can be granted and removed', function () {
    putMatrix($this->admin, $this->technician, matrixData($this->technician, [...roleSlugs($this->technician), 'users.manage']))
        ->assertSessionHasNoErrors();
    expect(roleSlugs($this->technician))->toContain('users.manage');

    putMatrix($this->admin, $this->technician, matrixData($this->technician, array_diff(roleSlugs($this->technician), ['users.manage'])))
        ->assertSessionHasNoErrors();
    expect(roleSlugs($this->technician))->not->toContain('users.manage');
});

test('roles with administrative permissions the operator lacks are read only', function () {
    $operator = matrixOperatorWith(['roles.manage']);
    $manager = Role::where('slug', 'manager')->first();

    $this->actingAs($operator)
        ->get("/admin/roles/permissions?role={$manager->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.can_manage', false)
            ->where('selected.read_only_reason', 'not_grantable'));

    // Gestor tem `audit.view`, que o operador não possui: nem a parte operacional pode ser mexida.
    putMatrix($operator, $manager, matrixData($manager, ['tickets.close']))->assertForbidden();

    expect(roleSlugs($manager))->toHaveCount(11);
});

test('the catalog marks which permissions the operator can grant', function () {
    $operator = matrixOperatorWith(['roles.manage', 'users.manage']);
    $catalog = collect($this->actingAs($operator)->get('/admin/roles/permissions')->viewData('page')['props']['catalog'])
        ->flatMap(fn ($group) => $group['permissions'])->keyBy('slug');

    expect($catalog['users.manage']['grantable'])->toBeTrue()
        ->and($catalog['roles.manage']['grantable'])->toBeTrue()
        ->and($catalog['sla.manage']['grantable'])->toBeFalse()
        ->and($catalog['audit.view']['grantable'])->toBeFalse()
        ->and($catalog['tickets.close']['grantable'])->toBeTrue();
});

/* ---------- Auditoria e consistência ---------- */

test('the effective changes are audited', function () {
    $before = roleSlugs($this->technician);
    $desired = [...array_diff($before, ['tickets.resolve']), 'tickets.close', 'users.manage'];

    putMatrix($this->admin, $this->technician, matrixData($this->technician, $desired))->assertSessionHasNoErrors();

    $event = AuditLog::sole();
    $after = roleSlugs($this->technician);

    expect($event->event_type)->toBe(AdminEvent::RolePermissionsUpdated)
        ->and($event->auditable->is($this->technician))->toBeTrue()
        ->and($event->user_id)->toBe($this->admin->id)
        ->and($event->old_values)->toBe(['permissions' => $before])
        ->and($event->new_values)->toBe([
            'permissions' => $after,
            'added' => ['tickets.close', 'users.manage'],
            'removed' => ['tickets.resolve'],
        ]);
});

test('a failure during the batch leaves no partial change', function () {
    $before = pivotSnapshot();
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));
    $this->withoutExceptionHandling();

    expect(fn () => putMatrix($this->admin, $this->technician, matrixData($this->technician, ['tickets.close'])))
        ->toThrow(RuntimeException::class);

    expect(pivotSnapshot())->toBe($before);
});

/* ---------- Navegação ---------- */

test('the role listing links to the matrix', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles')
        ->assertInertia(fn (Assert $page) => $page->where('can.matrix', true));
});
