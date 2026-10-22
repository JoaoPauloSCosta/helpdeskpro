<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
});

/** Nomes retornados na página atual da listagem de papéis. */
function listedRoles(TestResponse $response): array
{
    return collect($response->viewData('page')['props']['roles']['data'])->pluck('name')->all();
}

function roleListingProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** Papel personalizado só com a permissão informada. */
function roleOperatorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Somente {$permission}", 'slug' => str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/roles')->assertRedirect('/login');
});

test('users without roles.manage get a neutral access denied page', function (string $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->get('/admin/roles')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->missing('roles')
            ->missing('summary'));
})->with(['requester', 'technician', 'manager']);

test('other administrative permissions do not grant access to roles', function (string $permission) {
    $this->actingAs(roleOperatorWithOnly($permission))->get('/admin/roles')->assertForbidden();
})->with(['users.manage', 'departments.manage', 'categories.manage', 'audit.view']);

test('access follows the permission, not the role name', function () {
    $this->actingAs(roleOperatorWithOnly('roles.manage'))->get('/admin/roles')->assertOk();

    Role::where('slug', 'admin')->first()->permissions()->detach();
    $this->actingAs($this->admin->fresh())->get('/admin/roles')->assertForbidden();
});

test('inactive administrators are denied', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())
        ->get('/admin/roles')
        ->assertForbidden();
});

test('authorized users receive the listing page with the create action', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Roles/Index')
            ->has('roles.data', 4)
            ->where('roles.per_page', 15)
            ->where('filters', [])
            ->where('sort', ['key' => 'name', 'direction' => 'asc'])
            ->where('can.create', true));
});

/* ---------- Dados ---------- */

test('rows match the persisted roles and their real associations', function () {
    $role = Role::create(['name' => 'Auditor', 'slug' => 'auditor', 'description' => 'Somente consulta']);
    $role->permissions()->attach(Permission::whereIn('slug', ['audit.view', 'tickets.view_all_departments'])->pluck('id'), ['created_at' => now()]);
    User::factory()->count(2)->create(['role_id' => $role->id]);
    User::factory()->inactive()->create(['role_id' => $role->id]);

    $row = roleListingProps($this->actingAs($this->admin)->get('/admin/roles?q=auditor'))['roles']['data'][0];

    expect($row)->toBe([
        'id' => $role->id,
        'name' => 'Auditor',
        'slug' => 'auditor',
        'description' => 'Somente consulta',
        'is_system' => false,
        'users_count' => 3,
        'permissions_count' => 2,
        'can_edit' => true,
    ]);
});

test('seeded roles are listed as system roles with the matrix permission counts', function () {
    $rows = collect(roleListingProps($this->actingAs($this->admin)->get('/admin/roles'))['roles']['data'])->keyBy('slug');

    expect($rows->keys()->all())->toBe(['admin', 'manager', 'requester', 'technician'])
        ->and($rows->every(fn (array $row) => $row['is_system']))->toBeTrue();

    foreach (RolesAndPermissionsSeeder::ROLES as $slug => [, $permissions]) {
        expect($rows[$slug]['permissions_count'])->toBe(count($permissions));
    }

    expect($rows['admin']['users_count'])->toBe(1);
});

test('the summary counts every role and associated user, not only the current page or search', function () {
    foreach (range(1, 14) as $i) {
        Role::create(['name' => "Papel {$i}", 'slug' => "papel-{$i}"]);
    }
    User::factory()->count(2)->withRole('technician')->create();
    User::factory()->create(['role_id' => null]);

    $this->actingAs($this->admin)
        ->get('/admin/roles?q=papel&page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles.total', 14)
            ->where('summary', ['total' => 18, 'system' => 4, 'users' => 3]));
});

test('an empty base is distinguishable from a search without results', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles?q=inexistente')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles.total', 0)
            ->where('summary.total', 4)
            ->where('filters', ['q' => 'inexistente']));
});

test('the listing does not modify roles, users or permissions', function () {
    $snapshot = fn () => [
        Role::orderBy('id')->get(['id', 'name', 'slug', 'description', 'is_system', 'updated_at'])->toArray(),
        Permission::count(),
        DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])->toArray(),
        User::orderBy('id')->pluck('role_id', 'id')->all(),
    ];
    $before = $snapshot();
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)->get('/admin/roles?q=admin&sort=users_count&direction=desc')->assertOk();

    expect($snapshot())->toEqual($before);
});

/* ---------- Pesquisa ---------- */

test('search matches the name', function () {
    expect(listedRoles($this->actingAs($this->admin)->get('/admin/roles?q=técn')))->toBe(['Técnico']);
});

test('search matches the description', function () {
    Role::create(['name' => 'Auditor', 'slug' => 'auditor', 'description' => 'Consulta da trilha de auditoria']);

    expect(listedRoles($this->actingAs($this->admin)->get('/admin/roles?q=trilha')))->toBe(['Auditor']);
});

test('LIKE wildcards in the search are treated as literal text', function () {
    expect(listedRoles($this->actingAs($this->admin)->get('/admin/roles?q=%25')))->toBe([])
        ->and(listedRoles($this->actingAs($this->admin)->get('/admin/roles?q=_')))->toBe([]);
});

test('the applied search is returned trimmed and without empty values', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles?q=%20gestor%20')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['q' => 'gestor'])->has('roles.data', 1));

    $this->actingAs($this->admin)
        ->get('/admin/roles?q=')
        ->assertInertia(fn (Assert $page) => $page->where('filters', []));
});

test('invalid parameters are rejected', function (string $query, string $field) {
    $this->actingAs($this->admin)
        ->get("/admin/roles?$query")
        ->assertRedirect(route('admin.roles.index'))
        ->assertSessionHasErrors($field);
})->with([
    ['sort=slug', 'sort'],
    ['sort=id;drop', 'sort'],
    ['direction=sideways', 'direction'],
    ['page=0', 'page'],
    ['page=abc', 'page'],
    ['q[]=x', 'q'],
    ['q='.str_repeat('a', 101), 'q'],
]);

/* ---------- Ordenação e paginação ---------- */

test('sorting by name and by associated users works', function () {
    User::factory()->count(3)->withRole('requester')->create();
    User::factory()->count(2)->withRole('technician')->create();
    $get = fn (string $query) => listedRoles($this->actingAs($this->admin)->get("/admin/roles?$query")->assertOk());

    expect($get(''))->toBe(['Administrador', 'Gestor', 'Solicitante', 'Técnico'])
        ->and($get('sort=name&direction=desc'))->toBe(['Técnico', 'Solicitante', 'Gestor', 'Administrador'])
        ->and($get('sort=users_count&direction=desc'))->toBe(['Solicitante', 'Técnico', 'Administrador', 'Gestor'])
        ->and($get('sort=users_count&direction=asc'))->toBe(['Gestor', 'Administrador', 'Técnico', 'Solicitante']);
});

test('pagination happens on the server and keeps search and sorting', function () {
    foreach (range(1, 18) as $i) {
        Role::create(['name' => sprintf('Papel %02d', $i), 'slug' => "papel-{$i}"]);
    }

    $response = $this->actingAs($this->admin)
        ->get('/admin/roles?q=papel&sort=name&direction=desc&page=2')
        ->assertOk();
    $page = roleListingProps($response)['roles'];

    expect($page['total'])->toBe(18)
        ->and($page['current_page'])->toBe(2)
        ->and($page['data'])->toHaveCount(3)
        ->and(listedRoles($response))->toBe(['Papel 03', 'Papel 02', 'Papel 01'])
        ->and($page['prev_page_url'])->toContain('q=papel')
        ->and($page['prev_page_url'])->toContain('sort=name')
        ->and($page['prev_page_url'])->toContain('direction=desc');
});

/* ---------- Navegação ---------- */

test('role administrators see the administration area without other admin permissions', function () {
    $operator = roleOperatorWithOnly('roles.manage');

    $this->actingAs($operator)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', ['roles.manage']));

    $this->actingAs($operator)->get('/admin/users')->assertForbidden();
    $this->actingAs($operator)->get('/admin/categories')->assertForbidden();
});
