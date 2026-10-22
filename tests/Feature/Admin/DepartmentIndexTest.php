<?php

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
});

/** Nomes retornados na página atual da listagem de departamentos. */
function listedDepartments(TestResponse $response): array
{
    return collect($response->viewData('page')['props']['departments']['data'])->pluck('name')->all();
}

function departmentListingProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** Papel personalizado só com a permissão informada. */
function userWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Somente {$permission}", 'slug' => str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/departments')->assertRedirect('/login');
});

test('users without departments.manage get a neutral access denied page', function (string $role) {
    Department::factory()->create();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get('/admin/departments')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->missing('departments'));
})->with(['requester', 'technician', 'manager']);

test('users.manage alone does not grant access to departments', function () {
    $this->actingAs(userWithOnly('users.manage'))->get('/admin/departments')->assertForbidden();
});

test('access follows the permission, not the role name', function () {
    $this->actingAs(userWithOnly('departments.manage'))->get('/admin/departments')->assertOk();

    Role::where('slug', 'admin')->first()->permissions()->detach();
    $this->actingAs($this->admin->fresh())->get('/admin/departments')->assertForbidden();
});

test('inactive administrators are denied', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())
        ->get('/admin/departments')
        ->assertForbidden();
});

test('authorized users receive the listing page', function () {
    Department::factory()->create(['name' => 'Suporte']);

    $this->actingAs($this->admin)
        ->get('/admin/departments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Departments/Index')
            ->has('departments.data', 1)
            ->where('departments.per_page', 15)
            ->where('filters', [])
            ->where('sort', ['key' => 'name', 'direction' => 'asc'])
            ->where('options.statuses', [
                ['value' => 'active', 'label' => 'Ativo'],
                ['value' => 'inactive', 'label' => 'Inativo'],
            ])
            // A criação (ADM-05) existe e o operador tem `departments.manage`.
            ->where('can.create', true));
});

test('the empty listing is distinguishable from an error', function () {
    $this->actingAs($this->admin)
        ->get('/admin/departments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('departments.total', 0)->where('filters', []));
});

/* ---------- Dados ---------- */

test('rows expose only the listing fields with real counts', function () {
    $department = Department::factory()->create(['name' => 'Financeiro', 'description' => 'Contas a pagar e receber']);
    User::factory()->count(2)->create(['department_id' => $department->id]);
    Ticket::factory()->count(3)->create(['department_id' => $department->id]);

    $row = departmentListingProps($this->actingAs($this->admin)->get('/admin/departments?q=Financeiro'))['departments']['data'][0];

    expect($row)->toBe([
        'id' => $department->id,
        'name' => 'Financeiro',
        'description' => 'Contas a pagar e receber',
        'is_active' => true,
        'users_count' => 2,
        'tickets_count' => 3,
        'created_at' => $department->created_at->toIso8601String(),
        'updated_at' => $department->updated_at->toIso8601String(),
        'can_edit' => true,
    ]);
});

test('the shared props do not expose sensitive attributes', function () {
    $department = Department::factory()->create();
    $member = User::factory()->create(['department_id' => $department->id]);

    $props = json_encode(departmentListingProps($this->actingAs($this->admin)->get('/admin/departments')));

    expect($props)->not->toContain($member->password)
        ->and($props)->not->toContain($member->email)
        ->and($props)->not->toContain('remember_token')
        ->and($props)->not->toContain('"password"');
});

test('inactive departments remain listed and identified', function () {
    $inactive = Department::factory()->create(['name' => 'Antigo', 'is_active' => false]);
    Ticket::factory()->create(['department_id' => $inactive->id]);

    $response = $this->actingAs($this->admin)->get('/admin/departments')->assertOk();
    $row = collect(departmentListingProps($response)['departments']['data'])->firstWhere('name', 'Antigo');

    expect($row['is_active'])->toBeFalse()
        ->and($row['tickets_count'])->toBe(1);
});

test('the listing does not modify departments or their references', function () {
    $department = Department::factory()->create(['is_active' => false]);
    $ticket = Ticket::factory()->create(['department_id' => $department->id]);
    $member = User::factory()->create(['department_id' => $department->id]);
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)->get('/admin/departments?status=inactive')->assertOk();

    expect($department->fresh()->updated_at->equalTo($department->updated_at))->toBeTrue()
        ->and($department->fresh()->is_active)->toBeFalse()
        ->and($ticket->fresh()->department_id)->toBe($department->id)
        ->and($member->fresh()->department_id)->toBe($department->id);
});

/* ---------- Pesquisa e filtros ---------- */

test('search matches the name', function () {
    Department::factory()->create(['name' => 'Recursos Humanos']);
    Department::factory()->create(['name' => 'Tecnologia']);

    expect(listedDepartments($this->actingAs($this->admin)->get('/admin/departments?q=humanos')))->toBe(['Recursos Humanos']);
});

test('search matches the description', function () {
    Department::factory()->create(['name' => 'Financeiro', 'description' => 'Folha de pagamento']);
    Department::factory()->create(['name' => 'Tecnologia', 'description' => 'Infraestrutura']);

    expect(listedDepartments($this->actingAs($this->admin)->get('/admin/departments?q=pagamento')))->toBe(['Financeiro']);
});

test('LIKE wildcards in the search are treated as literal text', function () {
    Department::factory()->create(['name' => 'Suporte']);

    expect(listedDepartments($this->actingAs($this->admin)->get('/admin/departments?q=%25')))->toBe([]);
});

test('status and combined filters are applied on the server', function () {
    Department::factory()->create(['name' => 'Suporte Nível 1']);
    Department::factory()->create(['name' => 'Suporte Legado', 'is_active' => false]);
    Department::factory()->create(['name' => 'Financeiro', 'is_active' => false]);
    $get = fn (string $query) => listedDepartments($this->actingAs($this->admin)->get("/admin/departments?$query")->assertOk());

    expect($get('status=active'))->toBe(['Suporte Nível 1'])
        ->and($get('status=inactive'))->toBe(['Financeiro', 'Suporte Legado'])
        ->and($get('q=suporte&status=inactive'))->toBe(['Suporte Legado'])
        ->and($get(''))->toBe(['Financeiro', 'Suporte Legado', 'Suporte Nível 1']);
});

test('applied filters are returned trimmed and without empty values', function () {
    $this->actingAs($this->admin)
        ->get('/admin/departments?q=%20sup%20&status=')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['q' => 'sup']));
});

test('invalid parameters are rejected', function (string $query, string $field) {
    $this->actingAs($this->admin)
        ->get("/admin/departments?$query")
        ->assertRedirect(route('admin.departments.index'))
        ->assertSessionHasErrors($field);
})->with([
    ['status=deleted', 'status'],
    ['sort=description', 'sort'],
    ['direction=sideways', 'direction'],
    ['page=0', 'page'],
    ['q[]=x', 'q'],
    ['q='.str_repeat('a', 101), 'q'],
]);

/* ---------- Ordenação e paginação ---------- */

test('sorting by name and dates works', function () {
    Department::factory()->create(['name' => 'Beta', 'created_at' => now()->subDays(1), 'updated_at' => now()->subDays(3)]);
    Department::factory()->create(['name' => 'Alfa', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(1)]);
    $get = fn (string $query) => listedDepartments($this->actingAs($this->admin)->get("/admin/departments?$query")->assertOk());

    expect($get(''))->toBe(['Alfa', 'Beta'])
        ->and($get('sort=name&direction=desc'))->toBe(['Beta', 'Alfa'])
        ->and($get('sort=created_at&direction=desc'))->toBe(['Beta', 'Alfa'])
        ->and($get('sort=updated_at&direction=desc'))->toBe(['Alfa', 'Beta']);
});

test('pagination happens on the server and keeps filters and sorting', function () {
    Department::factory()->count(18)->create(['is_active' => false]);
    Department::factory()->count(4)->create();

    $response = $this->actingAs($this->admin)
        ->get('/admin/departments?status=inactive&sort=name&direction=desc&page=2')
        ->assertOk();
    $page = departmentListingProps($response)['departments'];

    expect($page['total'])->toBe(18)
        ->and($page['current_page'])->toBe(2)
        ->and($page['data'])->toHaveCount(3)
        ->and($page['prev_page_url'])->toContain('status=inactive')
        ->and($page['prev_page_url'])->toContain('sort=name')
        ->and($page['prev_page_url'])->toContain('direction=desc');

    $firstPage = listedDepartments($this->actingAs($this->admin)->get('/admin/departments?status=inactive&sort=name&direction=desc'));
    expect(array_intersect($firstPage, listedDepartments($response)))->toBe([]);
});

/* ---------- Navegação ---------- */

test('department administrators see the administration area without users.manage', function () {
    $operator = userWithOnly('departments.manage');

    $this->actingAs($operator)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', ['departments.manage']));

    // Sem a permissão, a listagem de usuários continua negada.
    $this->actingAs($operator)->get('/admin/users')->assertForbidden();
});
