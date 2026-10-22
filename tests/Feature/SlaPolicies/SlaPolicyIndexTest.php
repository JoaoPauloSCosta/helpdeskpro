<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
});

/** Prioridades (rótulos) retornadas na página atual da listagem de políticas. */
function listedSlaPriorities(TestResponse $response): array
{
    return collect($response->viewData('page')['props']['policies']['data'])->pluck('priority')->all();
}

function slaPolicyListingProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** Papel personalizado só com a permissão informada. */
function slaOperatorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Somente {$permission}", 'slug' => str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/sla-policies')->assertRedirect('/login');
});

test('users without sla.manage get a neutral access denied page without policy data', function (string $role) {
    SlaPolicy::factory()->create(['priority' => 'high', 'resolution_minutes' => 480]);

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get('/sla-policies')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->missing('policies')
            ->missing('summary'));
})->with(['requester', 'technician', 'manager']);

test('other permissions do not grant access to SLA policies', function (string $permission) {
    $this->actingAs(slaOperatorWithOnly($permission))->get('/sla-policies')->assertForbidden();
})->with(['roles.manage', 'categories.manage', 'tickets.change_priority', 'audit.view']);

test('access follows the permission, not the role name', function () {
    $this->actingAs(slaOperatorWithOnly('sla.manage'))->get('/sla-policies')->assertOk();

    Role::where('slug', 'admin')->first()->permissions()->detach();
    $this->actingAs($this->admin->fresh())->get('/sla-policies')->assertForbidden();
});

test('inactive administrators are denied', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())
        ->get('/sla-policies')
        ->assertForbidden();
});

test('authorized users receive the listing page', function () {
    SlaPolicy::factory()->create(['priority' => 'medium']);

    $this->actingAs($this->admin)
        ->get('/sla-policies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SlaPolicies/Index')
            ->has('policies.data', 1)
            ->where('policies.per_page', 15)
            ->where('filters', [])
            ->where('sort', ['key' => 'priority', 'direction' => 'asc'])
            ->where('options.priorities', [
                ['value' => 'low', 'label' => 'Baixa'],
                ['value' => 'medium', 'label' => 'Média'],
                ['value' => 'high', 'label' => 'Alta'],
                ['value' => 'critical', 'label' => 'Crítica'],
            ])
            ->where('options.statuses', [
                ['value' => 'active', 'label' => 'Ativas'],
                ['value' => 'inactive', 'label' => 'Inativas'],
            ])
            // Cadastro (SLA-02), com a mesma permissão.
            ->where('can.create', true));
});

/* ---------- Dados ---------- */

test('rows expose only the persisted listing fields', function () {
    $policy = SlaPolicy::factory()->create([
        'priority' => 'critical',
        'first_response_minutes' => 30,
        'resolution_minutes' => 240,
    ]);

    $row = slaPolicyListingProps($this->actingAs($this->admin)->get('/sla-policies'))['policies']['data'][0];

    expect($row)->toBe([
        'id' => $policy->id,
        'priority' => 'Crítica',
        'first_response_minutes' => 30,
        'resolution_minutes' => 240,
        'is_active' => true,
        'updated_at' => $policy->updated_at->toIso8601String(),
        'can_view' => true,
        'can_edit' => true,
    ]);
});

test('an empty base is distinguishable from a filter without results', function () {
    $this->actingAs($this->admin)
        ->get('/sla-policies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('policies.total', 0)
            ->where('summary', ['total' => 0, 'active' => 0, 'inactive' => 0, 'priorities' => 4, 'covered' => 0]));

    SlaPolicy::factory()->create(['priority' => 'low']);

    $this->actingAs($this->admin)
        ->get('/sla-policies?priority=critical')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('policies.total', 0)
            ->where('summary.total', 1)
            ->where('filters', ['priority' => 'critical']));
});

test('the listing includes active and inactive policies', function () {
    SlaPolicy::factory()->create(['priority' => 'high']);
    SlaPolicy::factory()->inactive()->create(['priority' => 'low']);

    $rows = collect(slaPolicyListingProps($this->actingAs($this->admin)->get('/sla-policies'))['policies']['data']);

    expect($rows->pluck('is_active', 'priority')->all())->toBe(['Baixa' => false, 'Alta' => true]);
});

test('the summary reflects every policy, not only the current filter or page', function () {
    SlaPolicy::factory()->create(['priority' => 'low']);
    SlaPolicy::factory()->create(['priority' => 'high']);
    SlaPolicy::factory()->inactive()->count(3)->create(['priority' => 'critical']);

    $this->actingAs($this->admin)
        ->get('/sla-policies?status=inactive')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('policies.total', 3)
            ->where('summary', ['total' => 5, 'active' => 2, 'inactive' => 3, 'priorities' => 4, 'covered' => 2]));
});

test('the coverage counts priorities with an active policy, ignoring inactive ones', function () {
    SlaPolicy::factory()->create(['priority' => 'medium']);
    SlaPolicy::factory()->create(['priority' => 'high']);
    SlaPolicy::factory()->inactive()->create(['priority' => 'high']);
    SlaPolicy::factory()->inactive()->create(['priority' => 'low']);

    $this->actingAs($this->admin)
        ->get('/sla-policies')
        ->assertInertia(fn (Assert $page) => $page->where('summary.covered', 2));
});

test('the listing does not modify policies, targets or tickets', function () {
    $policy = SlaPolicy::factory()->inactive()->create(['priority' => 'high', 'first_response_minutes' => 60, 'resolution_minutes' => 600]);
    $ticket = Ticket::factory()->create();
    $ticketSnapshot = $ticket->fresh()->getAttributes();
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)->get('/sla-policies?status=inactive')->assertOk();

    $fresh = $policy->fresh();
    expect($fresh->updated_at->equalTo($policy->updated_at))->toBeTrue()
        ->and($fresh->is_active)->toBeFalse()
        ->and($fresh->first_response_minutes)->toBe(60)
        ->and($fresh->resolution_minutes)->toBe(600)
        ->and(SlaPolicy::count())->toBe(1)
        ->and($ticket->fresh()->getAttributes())->toBe($ticketSnapshot);
});

/* ---------- Filtros ---------- */

test('priority, status and combined filters are applied on the server', function () {
    SlaPolicy::factory()->create(['priority' => 'high']);
    SlaPolicy::factory()->inactive()->create(['priority' => 'high']);
    SlaPolicy::factory()->inactive()->create(['priority' => 'low']);
    $get = fn (string $query) => collect(slaPolicyListingProps($this->actingAs($this->admin)->get("/sla-policies?$query")->assertOk())['policies']['data'])
        ->map(fn (array $row) => $row['priority'].($row['is_active'] ? ' ativa' : ' inativa'))
        ->all();

    expect($get('priority=high'))->toBe(['Alta ativa', 'Alta inativa'])
        ->and($get('status=active'))->toBe(['Alta ativa'])
        ->and($get('status=inactive'))->toBe(['Baixa inativa', 'Alta inativa'])
        ->and($get('priority=high&status=inactive'))->toBe(['Alta inativa'])
        ->and($get('priority=low&status=active'))->toBe([]);
});

test('applied filters are returned trimmed and without empty values', function () {
    $this->actingAs($this->admin)
        ->get('/sla-policies?priority=&status=active')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['status' => 'active']));
});

test('invalid parameters are rejected', function (string $query, string $field) {
    $this->actingAs($this->admin)
        ->get("/sla-policies?$query")
        ->assertRedirect(route('sla-policies.index'))
        ->assertSessionHasErrors($field);
})->with([
    ['priority=urgent', 'priority'],
    ['priority[]=high', 'priority'],
    ['status=deleted', 'status'],
    ['status[]=active', 'status'],
    ['sort=is_active', 'sort'],
    ['sort=id;drop', 'sort'],
    ['direction=sideways', 'direction'],
    ['page=0', 'page'],
    ['page=abc', 'page'],
]);

/* ---------- Ordenação e paginação ---------- */

test('priority sorting follows the domain order, not the alphabet', function () {
    foreach (['critical', 'low', 'high', 'medium'] as $priority) {
        SlaPolicy::factory()->create(['priority' => $priority]);
    }

    expect(listedSlaPriorities($this->actingAs($this->admin)->get('/sla-policies')))->toBe(['Baixa', 'Média', 'Alta', 'Crítica'])
        ->and(listedSlaPriorities($this->actingAs($this->admin)->get('/sla-policies?sort=priority&direction=desc')))
        ->toBe(['Crítica', 'Alta', 'Média', 'Baixa']);
});

test('sorting by targets and update date works', function () {
    SlaPolicy::factory()->create(['priority' => 'low', 'first_response_minutes' => 240, 'resolution_minutes' => 600, 'updated_at' => now()->subDays(1)]);
    SlaPolicy::factory()->create(['priority' => 'critical', 'first_response_minutes' => 15, 'resolution_minutes' => 900, 'updated_at' => now()->subDays(3)]);
    $get = fn (string $query) => listedSlaPriorities($this->actingAs($this->admin)->get("/sla-policies?$query")->assertOk());

    expect($get('sort=first_response_minutes&direction=asc'))->toBe(['Crítica', 'Baixa'])
        ->and($get('sort=resolution_minutes&direction=asc'))->toBe(['Baixa', 'Crítica'])
        ->and($get('sort=updated_at&direction=desc'))->toBe(['Baixa', 'Crítica']);
});

test('ties are broken deterministically by id', function () {
    $first = SlaPolicy::factory()->create(['priority' => 'high']);
    $second = SlaPolicy::factory()->inactive()->create(['priority' => 'high']);
    $ids = fn (string $query) => collect(slaPolicyListingProps($this->actingAs($this->admin)->get("/sla-policies?$query"))['policies']['data'])->pluck('id')->all();

    expect($ids(''))->toBe([$first->id, $second->id])
        ->and($ids('direction=desc'))->toBe([$second->id, $first->id]);
});

test('pagination happens on the server and keeps filters and sorting', function () {
    SlaPolicy::factory()->inactive()->count(18)->create();
    // Ativas em prioridades distintas: só pode haver uma ativa por prioridade.
    SlaPolicy::factory()->create(['priority' => 'low']);
    SlaPolicy::factory()->create(['priority' => 'high']);

    $response = $this->actingAs($this->admin)
        ->get('/sla-policies?status=inactive&sort=updated_at&direction=desc&page=2')
        ->assertOk();
    $page = slaPolicyListingProps($response)['policies'];

    expect($page['total'])->toBe(18)
        ->and($page['current_page'])->toBe(2)
        ->and($page['data'])->toHaveCount(3)
        ->and($page['prev_page_url'])->toContain('status=inactive')
        ->and($page['prev_page_url'])->toContain('sort=updated_at')
        ->and($page['prev_page_url'])->toContain('direction=desc');

    $ids = fn (TestResponse $r) => collect(slaPolicyListingProps($r)['policies']['data'])->pluck('id')->all();
    $firstPage = $this->actingAs($this->admin)->get('/sla-policies?status=inactive&sort=updated_at&direction=desc');
    expect(array_intersect($ids($firstPage), $ids($response)))->toBe([]);
});

/* ---------- Navegação ---------- */

test('the details and edit actions follow sla.manage', function () {
    $policy = SlaPolicy::factory()->create();
    $operator = slaOperatorWithOnly('sla.manage');

    $this->actingAs($operator)
        ->get('/sla-policies')
        ->assertInertia(fn (Assert $page) => $page
            ->where('policies.data.0.can_view', true)
            ->where('policies.data.0.can_edit', true));

    $this->actingAs($operator)->get(route('sla-policies.show', $policy))->assertOk();
});

test('sla.manage alone reaches the listing without other administrative areas', function () {
    $operator = slaOperatorWithOnly('sla.manage');

    $this->actingAs($operator)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', ['sla.manage']));

    $this->actingAs($operator)->get('/admin/categories')->assertForbidden();
});
