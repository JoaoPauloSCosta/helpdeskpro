<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
});

/** Nomes retornados na página atual da listagem de categorias. */
function listedCategories(TestResponse $response): array
{
    return collect($response->viewData('page')['props']['categories']['data'])->pluck('name')->all();
}

function categoryListingProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** Papel personalizado só com a permissão informada. */
function categoryOperatorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Somente {$permission}", 'slug' => str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/categories')->assertRedirect('/login');
});

test('users without categories.manage get a neutral access denied page', function (string $role) {
    TicketCategory::factory()->create();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get('/admin/categories')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->missing('categories')
            ->missing('summary'));
})->with(['requester', 'technician', 'manager']);

test('other administrative permissions do not grant access to categories', function (string $permission) {
    $this->actingAs(categoryOperatorWithOnly($permission))->get('/admin/categories')->assertForbidden();
})->with(['users.manage', 'departments.manage', 'tickets.change_category']);

test('access follows the permission, not the role name', function () {
    $this->actingAs(categoryOperatorWithOnly('categories.manage'))->get('/admin/categories')->assertOk();

    Role::where('slug', 'admin')->first()->permissions()->detach();
    $this->actingAs($this->admin->fresh())->get('/admin/categories')->assertForbidden();
});

test('inactive administrators are denied', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())
        ->get('/admin/categories')
        ->assertForbidden();
});

test('authorized users receive the listing page', function () {
    TicketCategory::factory()->create(['name' => 'Hardware']);

    $this->actingAs($this->admin)
        ->get('/admin/categories')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Categories/Index')
            ->has('categories.data', 1)
            ->where('categories.per_page', 15)
            ->where('filters', [])
            ->where('sort', ['key' => 'name', 'direction' => 'asc'])
            ->where('options.statuses', [
                ['value' => 'active', 'label' => 'Ativas'],
                ['value' => 'inactive', 'label' => 'Inativas'],
            ]));
});

test('an empty base is distinguishable from a search without results', function () {
    $this->actingAs($this->admin)
        ->get('/admin/categories')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('categories.total', 0)
            ->where('summary', ['total' => 0, 'active' => 0, 'inactive' => 0]));

    TicketCategory::factory()->create(['name' => 'Hardware']);

    $this->actingAs($this->admin)
        ->get('/admin/categories?q=inexistente')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('categories.total', 0)
            ->where('summary.total', 1)
            ->where('filters', ['q' => 'inexistente']));
});

/* ---------- Dados ---------- */

test('rows expose only the listing fields', function () {
    $category = TicketCategory::factory()->create(['name' => 'Rede', 'description' => 'Conectividade e VPN']);

    $row = categoryListingProps($this->actingAs($this->admin)->get('/admin/categories'))['categories']['data'][0];

    expect($row)->toBe([
        'id' => $category->id,
        'name' => 'Rede',
        'description' => 'Conectividade e VPN',
        'is_active' => true,
        'updated_at' => $category->updated_at->toIso8601String(),
        'can_edit' => true,
    ]);
});

test('the listing includes active and inactive categories', function () {
    TicketCategory::factory()->create(['name' => 'Hardware']);
    TicketCategory::factory()->create(['name' => 'Legado', 'is_active' => false]);

    $rows = collect(categoryListingProps($this->actingAs($this->admin)->get('/admin/categories'))['categories']['data']);

    expect($rows->pluck('is_active', 'name')->all())->toBe(['Hardware' => true, 'Legado' => false]);
});

test('inactive categories stay in the administration but not in the ticket creation catalog', function () {
    TicketCategory::factory()->create(['name' => 'Hardware']);
    TicketCategory::factory()->create(['name' => 'Legado', 'is_active' => false]);

    expect(listedCategories($this->actingAs($this->admin)->get('/admin/categories')))->toBe(['Hardware', 'Legado']);

    $this->actingAs(User::factory()->withRole('requester')->create())
        ->get('/tickets/create')
        ->assertInertia(fn (Assert $page) => $page->where('categories.0.label', 'Hardware')->has('categories', 1));
});

test('the summary counts every category, not only the current page or search', function () {
    TicketCategory::factory()->count(17)->create();
    TicketCategory::factory()->count(4)->create(['is_active' => false]);

    $this->actingAs($this->admin)
        ->get('/admin/categories?status=inactive&page=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('categories.total', 4)
            ->where('summary', ['total' => 21, 'active' => 17, 'inactive' => 4]));
});

test('the listing does not modify categories or their ticket references', function () {
    $category = TicketCategory::factory()->create(['is_active' => false]);
    $ticket = Ticket::factory()->create(['category_id' => $category->id]);
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)->get('/admin/categories?status=inactive')->assertOk();

    expect($category->fresh()->updated_at->equalTo($category->updated_at))->toBeTrue()
        ->and($category->fresh()->is_active)->toBeFalse()
        ->and(TicketCategory::count())->toBe(1)
        ->and($ticket->fresh()->category_id)->toBe($category->id);
});

/* ---------- Pesquisa e filtros ---------- */

test('search matches the name', function () {
    TicketCategory::factory()->create(['name' => 'Impressoras']);
    TicketCategory::factory()->create(['name' => 'Rede']);

    expect(listedCategories($this->actingAs($this->admin)->get('/admin/categories?q=impress')))->toBe(['Impressoras']);
});

test('search matches the description', function () {
    TicketCategory::factory()->create(['name' => 'Rede', 'description' => 'Wi-Fi e VPN']);
    TicketCategory::factory()->create(['name' => 'Hardware', 'description' => 'Monitores e teclados']);

    expect(listedCategories($this->actingAs($this->admin)->get('/admin/categories?q=vpn')))->toBe(['Rede']);
});

test('LIKE wildcards in the search are treated as literal text', function () {
    TicketCategory::factory()->create(['name' => 'Hardware']);

    expect(listedCategories($this->actingAs($this->admin)->get('/admin/categories?q=%25')))->toBe([])
        ->and(listedCategories($this->actingAs($this->admin)->get('/admin/categories?q=_')))->toBe([]);
});

test('status and combined filters are applied on the server', function () {
    TicketCategory::factory()->create(['name' => 'Software Interno']);
    TicketCategory::factory()->create(['name' => 'Software Legado', 'is_active' => false]);
    TicketCategory::factory()->create(['name' => 'Fax', 'is_active' => false]);
    $get = fn (string $query) => listedCategories($this->actingAs($this->admin)->get("/admin/categories?$query")->assertOk());

    expect($get('status=active'))->toBe(['Software Interno'])
        ->and($get('status=inactive'))->toBe(['Fax', 'Software Legado'])
        ->and($get('q=software&status=inactive'))->toBe(['Software Legado'])
        ->and($get('q=software&status=active'))->toBe(['Software Interno'])
        ->and($get(''))->toBe(['Fax', 'Software Interno', 'Software Legado']);
});

test('applied filters are returned trimmed and without empty values', function () {
    $this->actingAs($this->admin)
        ->get('/admin/categories?q=%20rede%20&status=')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['q' => 'rede']));
});

test('invalid parameters are rejected', function (string $query, string $field) {
    $this->actingAs($this->admin)
        ->get("/admin/categories?$query")
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHasErrors($field);
})->with([
    ['status=deleted', 'status'],
    ['status[]=active', 'status'],
    ['sort=description', 'sort'],
    ['sort=id;drop', 'sort'],
    ['direction=sideways', 'direction'],
    ['page=0', 'page'],
    ['page=abc', 'page'],
    ['q[]=x', 'q'],
    ['q='.str_repeat('a', 101), 'q'],
]);

/* ---------- Ordenação e paginação ---------- */

test('sorting by name and update date works', function () {
    TicketCategory::factory()->create(['name' => 'Beta', 'updated_at' => now()->subDays(3)]);
    TicketCategory::factory()->create(['name' => 'Alfa', 'updated_at' => now()->subDays(1)]);
    $get = fn (string $query) => listedCategories($this->actingAs($this->admin)->get("/admin/categories?$query")->assertOk());

    expect($get(''))->toBe(['Alfa', 'Beta'])
        ->and($get('sort=name&direction=desc'))->toBe(['Beta', 'Alfa'])
        ->and($get('sort=updated_at&direction=desc'))->toBe(['Alfa', 'Beta'])
        ->and($get('sort=updated_at&direction=asc'))->toBe(['Beta', 'Alfa']);
});

test('ties are broken deterministically by id', function () {
    $first = TicketCategory::factory()->create(['name' => 'Mesmo nome']);
    $second = TicketCategory::factory()->create(['name' => 'Mesmo nome']);
    $ids = fn (string $query) => collect(categoryListingProps($this->actingAs($this->admin)->get("/admin/categories?$query"))['categories']['data'])->pluck('id')->all();

    expect($ids(''))->toBe([$first->id, $second->id])
        ->and($ids('direction=desc'))->toBe([$second->id, $first->id]);
});

test('pagination happens on the server and keeps filters and sorting', function () {
    TicketCategory::factory()->count(18)->create(['is_active' => false]);
    TicketCategory::factory()->count(4)->create();

    $response = $this->actingAs($this->admin)
        ->get('/admin/categories?q=&status=inactive&sort=name&direction=desc&page=2')
        ->assertOk();
    $page = categoryListingProps($response)['categories'];

    expect($page['total'])->toBe(18)
        ->and($page['current_page'])->toBe(2)
        ->and($page['data'])->toHaveCount(3)
        ->and($page['prev_page_url'])->toContain('status=inactive')
        ->and($page['prev_page_url'])->toContain('sort=name')
        ->and($page['prev_page_url'])->toContain('direction=desc');

    $firstPage = listedCategories($this->actingAs($this->admin)->get('/admin/categories?status=inactive&sort=name&direction=desc'));
    expect(array_intersect($firstPage, listedCategories($response)))->toBe([]);
});

/* ---------- Ações ---------- */

test('create and edit actions follow categories.manage', function () {
    TicketCategory::factory()->create();

    $this->actingAs(categoryOperatorWithOnly('categories.manage'))
        ->get('/admin/categories')
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.create', true)
            ->where('categories.data.0.can_edit', true));
});

/* ---------- Navegação ---------- */

test('category administrators see the administration area without other admin permissions', function () {
    $operator = categoryOperatorWithOnly('categories.manage');

    $this->actingAs($operator)
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', ['categories.manage']));

    $this->actingAs($operator)->get('/admin/departments')->assertForbidden();
    $this->actingAs($operator)->get('/admin/users')->assertForbidden();
});
