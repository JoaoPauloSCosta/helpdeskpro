<?php

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create(['name' => 'Administradora', 'email' => 'admin@example.com']);
});

/** E-mails retornados na página atual da listagem de usuários. */
function listedEmails(TestResponse $response): array
{
    return collect($response->viewData('page')['props']['users']['data'])->pluck('email')->all();
}

function userListingProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/users')->assertRedirect('/login');
});

test('users without users.manage get a neutral access denied page', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get('/admin/users')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->missing('users'));
})->with(['requester', 'technician', 'manager']);

test('users without a role are denied', function () {
    $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
});

test('access follows the permission, not the role name', function () {
    $custom = Role::create(['name' => 'Recursos Humanos', 'slug' => 'hr']);
    $custom->permissions()->attach(
        Permission::where('slug', 'users.manage')->value('id'),
        ['created_at' => now()],
    );
    $hr = User::factory()->create(['role_id' => $custom->id]);

    $this->actingAs($hr)->get('/admin/users')->assertOk();

    // Sem a permissão, o papel "Administrador" sozinho não concede acesso.
    Role::where('slug', 'admin')->first()->permissions()->detach();
    $this->actingAs($this->admin->fresh())->get('/admin/users')->assertForbidden();
});

test('inactive administrators are denied', function () {
    $inactive = User::factory()->withRole('admin')->inactive()->create();

    $this->actingAs($inactive)->get('/admin/users')->assertForbidden();
});

test('authorized users receive the listing page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Index')
            ->has('users.data', 1)
            ->where('users.per_page', 15)
            ->where('filters', [])
            ->where('sort', ['key' => 'name', 'direction' => 'asc'])
            ->has('options.roles', 4)
            ->where('options.statuses', [
                ['value' => 'active', 'label' => 'Ativo'],
                ['value' => 'inactive', 'label' => 'Inativo'],
            ])
            // A criação (ADM-02) existe e o operador tem `users.manage`.
            ->where('can.create', true));
});

/* ---------- Dados expostos ---------- */

test('rows expose only the listing fields, without sensitive attributes', function () {
    $department = Department::factory()->create(['name' => 'Financeiro']);
    $user = User::factory()->withRole('technician')->create([
        'name' => 'Bruno Técnico',
        'email' => 'bruno@example.com',
        'department_id' => $department->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/admin/users?q=bruno')->assertOk();
    $row = userListingProps($response)['users']['data'][0];

    expect($row)->toBe([
        'id' => $user->id,
        'name' => 'Bruno Técnico',
        'email' => 'bruno@example.com',
        'role' => 'Técnico',
        'department' => 'Financeiro',
        'is_active' => true,
        'created_at' => $user->created_at->toIso8601String(),
        'can_edit' => true,
    ]);

    // Nada nas props (incluindo as compartilhadas) traz hash de senha ou token.
    $props = json_encode(userListingProps($response));
    expect($props)->not->toContain($user->password)
        ->and($props)->not->toContain($this->admin->password)
        ->and($props)->not->toContain($user->remember_token)
        ->and($props)->not->toContain('remember_token')
        ->and($props)->not->toContain('"password"');
});

test('users without role or department use neutral values', function () {
    User::factory()->create(['email' => 'novo@example.com']);

    $response = $this->actingAs($this->admin)->get('/admin/users?q=novo')->assertOk();

    expect(userListingProps($response)['users']['data'][0])
        ->role->toBeNull()
        ->department->toBeNull();
});

test('inactive users remain listed', function () {
    User::factory()->withRole('requester')->inactive()->create(['email' => 'inativo@example.com']);

    $response = $this->actingAs($this->admin)->get('/admin/users')->assertOk();

    expect(listedEmails($response))->toContain('inativo@example.com')
        ->and(collect(userListingProps($response)['users']['data'])->firstWhere('email', 'inativo@example.com')['is_active'])
        ->toBeFalse();
});

/* ---------- Pesquisa e filtros ---------- */

test('search matches the name', function () {
    User::factory()->create(['name' => 'Carla Souza', 'email' => 'c1@example.com']);
    User::factory()->create(['name' => 'Daniel Lima', 'email' => 'd1@example.com']);

    $response = $this->actingAs($this->admin)->get('/admin/users?q=souza')->assertOk();

    expect(listedEmails($response))->toBe(['c1@example.com']);
});

test('search matches the e-mail', function () {
    User::factory()->create(['name' => 'Carla', 'email' => 'carla@empresa.test']);
    User::factory()->create(['name' => 'Daniel', 'email' => 'daniel@outra.test']);

    $response = $this->actingAs($this->admin)->get('/admin/users?q=empresa.test')->assertOk();

    expect(listedEmails($response))->toBe(['carla@empresa.test']);
});

test('LIKE wildcards in the search are treated as literal text', function () {
    User::factory()->create(['name' => 'Sem curinga', 'email' => 'x@example.com']);

    $response = $this->actingAs($this->admin)->get('/admin/users?q=%25')->assertOk();

    expect(listedEmails($response))->toBe([]);
});

test('role, department and status filters are applied on the server', function () {
    $support = Department::factory()->create();
    $finance = Department::factory()->create();

    User::factory()->withRole('technician')->create(['email' => 'tec-suporte@example.com', 'department_id' => $support->id]);
    User::factory()->withRole('technician')->inactive()->create(['email' => 'tec-inativo@example.com', 'department_id' => $support->id]);
    User::factory()->withRole('technician')->create(['email' => 'tec-financeiro@example.com', 'department_id' => $finance->id]);
    User::factory()->withRole('requester')->create(['email' => 'sol-suporte@example.com', 'department_id' => $support->id]);
    User::factory()->create(['email' => 'sem-papel@example.com']);

    $technician = Role::where('slug', 'technician')->value('id');
    $get = fn (string $query) => listedEmails($this->actingAs($this->admin)->get("/admin/users?$query")->assertOk());

    expect($get("role=$technician"))->toEqualCanonicalizing(['tec-suporte@example.com', 'tec-inativo@example.com', 'tec-financeiro@example.com'])
        ->and($get("department={$support->id}"))->toEqualCanonicalizing(['tec-suporte@example.com', 'tec-inativo@example.com', 'sol-suporte@example.com'])
        ->and($get('status=inactive'))->toBe(['tec-inativo@example.com'])
        ->and($get('role=none'))->toBe(['sem-papel@example.com'])
        ->and($get('department=none'))->toEqualCanonicalizing(['admin@example.com', 'sem-papel@example.com'])
        // Filtros combinados.
        ->and($get("role=$technician&department={$support->id}&status=active"))->toBe(['tec-suporte@example.com'])
        ->and($get("q=tec&department={$finance->id}"))->toBe(['tec-financeiro@example.com']);
});

test('filter options come from real roles and departments', function () {
    Department::factory()->create(['name' => 'Antigo', 'is_active' => false]);
    Department::factory()->create(['name' => 'Suporte']);

    $response = $this->actingAs($this->admin)->get('/admin/users')->assertOk();
    $options = userListingProps($response)['options'];

    expect(collect($options['departments'])->pluck('label')->all())->toBe(['Antigo (inativo)', 'Suporte'])
        ->and(collect($options['roles'])->pluck('label')->all())->toEqualCanonicalizing(['Administrador', 'Gestor', 'Solicitante', 'Técnico']);
});

test('applied filters are returned trimmed and without empty values', function () {
    $this->actingAs($this->admin)
        ->get('/admin/users?q=%20ana%20&role=&status=active')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['q' => 'ana', 'status' => 'active']));
});

test('invalid parameters are rejected', function (string $query, string $field) {
    $this->actingAs($this->admin)
        ->get("/admin/users?$query")
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHasErrors($field);
})->with([
    ['role=abc', 'role'],
    ['role=0', 'role'],
    ['department=1%20or%201=1', 'department'],
    ['status=deleted', 'status'],
    ['sort=password', 'sort'],
    ['direction=sideways', 'direction'],
    ['page=0', 'page'],
    ['q[]=x', 'q'],
]);

/* ---------- Ordenação e paginação ---------- */

test('sorting by name, e-mail and creation date works', function () {
    User::factory()->create(['name' => 'Zeca', 'email' => 'a-zeca@example.com', 'created_at' => now()->subDays(1)]);
    User::factory()->create(['name' => 'Bia', 'email' => 'z-bia@example.com', 'created_at' => now()->subDays(3)]);
    $get = fn (string $query) => listedEmails($this->actingAs($this->admin)->get("/admin/users?$query")->assertOk());

    expect($get(''))->toBe(['admin@example.com', 'z-bia@example.com', 'a-zeca@example.com'])
        ->and($get('sort=name&direction=desc'))->toBe(['a-zeca@example.com', 'z-bia@example.com', 'admin@example.com'])
        ->and($get('sort=email'))->toBe(['a-zeca@example.com', 'admin@example.com', 'z-bia@example.com'])
        ->and($get('sort=created_at&direction=desc'))->toBe(['admin@example.com', 'a-zeca@example.com', 'z-bia@example.com']);
});

test('pagination happens on the server and keeps filters and sorting', function () {
    User::factory()->count(20)->withRole('technician')->create();
    User::factory()->count(3)->withRole('requester')->create();
    $technician = Role::where('slug', 'technician')->value('id');

    $response = $this->actingAs($this->admin)
        ->get("/admin/users?role=$technician&sort=email&direction=desc&page=2")
        ->assertOk();
    $users = userListingProps($response)['users'];

    expect($users['total'])->toBe(20)
        ->and($users['current_page'])->toBe(2)
        ->and($users['data'])->toHaveCount(5)
        ->and($users['next_page_url'])->toBeNull()
        ->and($users['prev_page_url'])->toContain("role=$technician")
        ->and($users['prev_page_url'])->toContain('sort=email')
        ->and($users['prev_page_url'])->toContain('direction=desc');

    $firstPage = listedEmails($this->actingAs($this->admin)->get("/admin/users?role=$technician&sort=email&direction=desc"));
    expect(array_intersect($firstPage, listedEmails($response)))->toBe([]);
});

/* ---------- Navegação ---------- */

test('effective permissions are shared for the navigation', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('users.manage')));

    $this->actingAs(User::factory()->withRole('manager')->create())
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', fn ($permissions) => ! collect($permissions)->contains('users.manage')));
});
