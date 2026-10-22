<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
});

/** Identificadores retornados na página atual do catálogo. */
function listedPermissions(TestResponse $response): array
{
    return collect($response->viewData('page')['props']['permissions']['data'])->pluck('slug')->all();
}

function catalogProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** Papel personalizado só com a permissão informada. */
function catalogViewerWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Leitor {$permission}", 'slug' => 'leitor-'.str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/** Catálogo, associações e papéis dos usuários, para provar que a consulta não muda nada. */
function rbacState(): array
{
    return [
        Permission::orderBy('id')->get()->toArray(),
        Role::orderBy('id')->get()->toArray(),
        DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all(),
        User::orderBy('id')->pluck('role_id', 'id')->all(),
    ];
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/permissions')->assertRedirect('/login');
});

test('users without roles.manage get a neutral access denied page', function (string $slug) {
    $this->actingAs(User::factory()->withRole($slug)->create())
        ->get('/admin/permissions')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403)->missing('permissions')->missing('detail'));
})->with(['requester', 'technician', 'manager']);

test('other administrative permissions do not grant access to the catalog', function (string $permission) {
    $this->actingAs(catalogViewerWithOnly($permission))->get('/admin/permissions')->assertForbidden();
})->with(['users.manage', 'categories.manage', 'audit.view']);

test('access follows the permission, not the role name', function () {
    $this->actingAs(catalogViewerWithOnly('roles.manage'))->get('/admin/permissions')->assertOk();

    Role::where('slug', 'admin')->first()->permissions()->detach();
    $this->actingAs($this->admin->fresh())->get('/admin/permissions')->assertForbidden();
});

test('inactive administrators are denied', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())->get('/admin/permissions')->assertForbidden();
});

test('unauthorized users cannot open details either', function () {
    $id = Permission::where('slug', 'users.manage')->value('id');

    $this->actingAs(User::factory()->withRole('technician')->create())
        ->get("/admin/permissions?selected={$id}")
        ->assertForbidden();
});

/* ---------- Catálogo ---------- */

test('authorized users receive the catalog page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/permissions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Permissions/Index')
            ->has('permissions.data', 15)
            ->where('permissions.total', 19)
            ->where('permissions.per_page', 15)
            ->where('filters', [])
            ->where('sort', ['key' => 'name', 'direction' => 'asc'])
            ->where('detail', null)
            ->where('selected', null));
});

test('the listed permissions are exactly the persisted catalog', function () {
    $page1 = listedPermissions($this->actingAs($this->admin)->get('/admin/permissions'));
    $page2 = listedPermissions($this->actingAs($this->admin)->get('/admin/permissions?page=2'));

    expect(collect([...$page1, ...$page2])->sort()->values()->all())
        ->toBe(Permission::orderBy('slug')->pluck('slug')->all())
        ->and(collect([...$page1, ...$page2])->sort()->values()->all())
        ->toBe(collect(array_keys(RolesAndPermissionsSeeder::PERMISSIONS))->sort()->values()->all());
});

test('rows expose only the catalog fields', function () {
    $row = collect(catalogProps($this->actingAs($this->admin)->get('/admin/permissions?q=users.manage'))['permissions']['data'])->sole();

    expect($row)->toBe([
        'id' => Permission::where('slug', 'users.manage')->value('id'),
        'name' => 'Gerenciar usuários',
        'slug' => 'users.manage',
        'description' => null,
        'group' => 'users',
        'group_label' => 'Usuários',
        'administrative' => true,
        // Somente o Administrador recebe a permissão na matriz inicial.
        'roles_count' => 1,
    ]);
});

test('the summary covers the whole catalog, not the search or page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/permissions?module=sla')
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.total', 1)
            ->where('summary', ['total' => 19, 'modules' => 8, 'administrative' => 6]));
});

test('the module filter offers only modules present in the catalog', function () {
    $modules = fn () => collect(catalogProps($this->actingAs($this->admin)->get('/admin/permissions'))['options']['modules'])->pluck('value')->all();

    expect($modules())->toBe(['tickets', 'comments', 'audit', 'users', 'departments', 'categories', 'roles', 'sla']);

    Permission::create(['name' => 'Exportar relatórios', 'slug' => 'reports.export']);

    expect($modules())->toContain('other');
});

test('an empty catalog is distinguishable from a search without results', function () {
    $this->actingAs($this->admin)
        ->get('/admin/permissions?q=inexistente')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('permissions.total', 0)->where('summary.total', 19)->where('filters', ['q' => 'inexistente']));

    // Catálogo realmente vazio, com um operador cujo acesso não depende do papel.
    $operator = User::factory()->create();
    $operator->setRelation('role', null);
    Gate::before(fn ($user, $ability) => $ability === 'viewAny' && $user->is($operator) ? true : null);
    DB::table('role_permissions')->delete();
    Permission::query()->delete();

    $this->actingAs($operator)
        ->get('/admin/permissions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.total', 0)
            ->where('summary', ['total' => 0, 'modules' => 0, 'administrative' => 0])
            ->where('options.modules', []));
});

test('the listing changes nothing', function () {
    $before = rbacState();
    $id = Permission::where('slug', 'tickets.close')->value('id');

    $this->actingAs($this->admin)->get('/admin/permissions?q=chamados&module=tickets&sort=slug&direction=desc')->assertOk();
    $this->actingAs($this->admin)->get("/admin/permissions?selected={$id}")->assertOk();
    $this->actingAs($this->admin)->get('/admin/permissions?selected=999999')->assertOk();

    expect(rbacState())->toEqual($before)
        ->and(AuditLog::count())->toBe(0);
});

test('viewing the catalog grants nothing to the viewer', function () {
    $operator = catalogViewerWithOnly('roles.manage');

    $this->actingAs($operator)->get('/admin/permissions')->assertOk();

    expect($operator->fresh()->effectivePermissions())->toBe(['roles.manage']);
    $this->actingAs($operator->fresh())->get('/admin/users')->assertForbidden();
});

/* ---------- Pesquisa e filtros ---------- */

test('search matches the name, the identifier and the description', function () {
    Permission::where('slug', 'sla.manage')->update(['description' => 'Metas de primeira resposta e resolução.']);
    $get = fn (string $q) => listedPermissions($this->actingAs($this->admin)->get('/admin/permissions?q='.urlencode($q)));

    expect($get('notas internas'))->toBe(['tickets.add_internal_note', 'tickets.view_internal_notes'])
        ->and($get('self_assign'))->toBe(['tickets.self_assign'])
        ->and($get('primeira resposta'))->toBe(['sla.manage']);
});

test('search matches the module name', function () {
    $slugs = listedPermissions($this->actingAs($this->admin)->get('/admin/permissions?q=comentarios'));

    expect($slugs)->toBe(['comments.edit_others']);
});

test('LIKE wildcards in the search are treated as literal text', function () {
    expect(listedPermissions($this->actingAs($this->admin)->get('/admin/permissions?q=%25')))->toBe([])
        // `_` aparece em identificadores reais, então só eles correspondem.
        ->and(listedPermissions($this->actingAs($this->admin)->get('/admin/permissions?q=self_')))->toBe(['tickets.self_assign']);
});

test('the module filter is applied on the server and combines with the search', function () {
    $get = fn (string $query) => listedPermissions($this->actingAs($this->admin)->get("/admin/permissions?$query")->assertOk());

    expect($get('module=roles'))->toBe(['roles.manage'])
        ->and($get('module=tickets'))->toHaveCount(12)
        ->and($get('module=tickets&q=notas'))->toBe(['tickets.add_internal_note', 'tickets.view_internal_notes'])
        ->and($get('module=users&q=notas'))->toBe([]);
});

test('permissions with an unknown prefix are listed under other', function () {
    Permission::create(['name' => 'Exportar relatórios', 'slug' => 'reports.export']);

    $rows = collect(catalogProps($this->actingAs($this->admin)->get('/admin/permissions?module=other'))['permissions']['data']);

    expect($rows->pluck('slug')->all())->toBe(['reports.export'])
        ->and($rows->first()['group_label'])->toBe('Outras');
});

test('applied filters are returned trimmed and without empty values', function () {
    $this->actingAs($this->admin)
        ->get('/admin/permissions?q=%20usuarios%20&module=')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['q' => 'usuarios']));
});

test('invalid parameters are rejected', function (string $query, string $field) {
    $this->actingAs($this->admin)
        ->get("/admin/permissions?$query")
        ->assertRedirect(route('admin.permissions.index'))
        ->assertSessionHasErrors($field);
})->with([
    ['module=financeiro', 'module'],
    ['module[]=tickets', 'module'],
    ['sort=description', 'sort'],
    ['sort=id;drop', 'sort'],
    ['direction=sideways', 'direction'],
    ['page=0', 'page'],
    ['q[]=x', 'q'],
    ['q='.str_repeat('a', 101), 'q'],
    ['selected=abc', 'selected'],
    ['selected=0', 'selected'],
]);

/* ---------- Ordenação e paginação ---------- */

test('sorting by name and identifier works', function () {
    $get = fn (string $query) => listedPermissions($this->actingAs($this->admin)->get("/admin/permissions?module=tickets&$query"));
    $byName = Permission::where('slug', 'like', 'tickets.%')->orderBy('name')->orderBy('id')->pluck('slug')->all();
    $bySlug = Permission::where('slug', 'like', 'tickets.%')->orderBy('slug')->pluck('slug')->all();

    expect($get(''))->toBe($byName)
        ->and($get('sort=slug'))->toBe($bySlug)
        ->and($get('sort=slug&direction=desc'))->toBe(array_reverse($bySlug));
});

test('pagination happens on the server and keeps filters and sorting', function () {
    $response = $this->actingAs($this->admin)->get('/admin/permissions?q=tickets&sort=slug&direction=desc&page=1')->assertOk();
    $page = catalogProps($response)['permissions'];

    expect($page['total'])->toBe(12)
        ->and($page['last_page'])->toBe(1);

    $second = catalogProps($this->actingAs($this->admin)->get('/admin/permissions?sort=slug&page=2'))['permissions'];

    expect($second['current_page'])->toBe(2)
        ->and($second['data'])->toHaveCount(4)
        ->and($second['prev_page_url'])->toContain('sort=slug');
});

/* ---------- Detalhes ---------- */

test('details return the selected permission and the roles that hold it', function () {
    $permission = Permission::where('slug', 'audit.view')->first();

    $this->actingAs($this->admin)
        ->get("/admin/permissions?selected={$permission->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected', $permission->id)
            ->where('detail', [
                'id' => $permission->id,
                'name' => 'Consultar auditoria',
                'slug' => 'audit.view',
                'description' => null,
                'group' => 'audit',
                'group_label' => 'Auditoria',
                'administrative' => true,
                'roles' => [
                    ['id' => Role::where('slug', 'admin')->value('id'), 'name' => 'Administrador', 'is_system' => true],
                    ['id' => Role::where('slug', 'manager')->value('id'), 'name' => 'Gestor', 'is_system' => true],
                ],
            ]));
});

test('details of a permission no role holds list no roles', function () {
    $id = Permission::where('slug', 'comments.edit_others')->value('id');

    $this->actingAs($this->admin)
        ->get("/admin/permissions?selected={$id}")
        ->assertInertia(fn (Assert $page) => $page->where('detail.slug', 'comments.edit_others')->where('detail.roles', []));
});

test('details keep the listing filters', function () {
    $id = Permission::where('slug', 'sla.manage')->value('id');

    $this->actingAs($this->admin)
        ->get("/admin/permissions?module=sla&selected={$id}")
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['module' => 'sla'])->where('detail.slug', 'sla.manage')->has('permissions.data', 1));
});

test('a missing permission is reported as not found without breaking the listing', function () {
    $this->actingAs($this->admin)
        ->get('/admin/permissions?selected=999999')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected', 999999)
            ->where('detail', null)
            ->has('permissions.data', 15));
});

/* ---------- Navegação ---------- */

test('the role listing links to the catalog', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles')
        ->assertInertia(fn (Assert $page) => $page->where('can.catalog', true));
});
