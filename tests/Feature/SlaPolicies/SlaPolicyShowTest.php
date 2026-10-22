<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
    $this->policy = SlaPolicy::factory()->create([
        'priority' => 'high',
        'first_response_minutes' => 60,
        'resolution_minutes' => 480,
    ]);
});

/** Papel personalizado só com as permissões informadas. */
function slaPolicyViewerWith(string ...$permissions): User
{
    $role = Role::create(['name' => 'Consulta '.implode(', ', $permissions), 'slug' => 'sla-viewer-'.Str::random(8)]);
    $role->permissions()->attach(Permission::whereIn('slug', $permissions)->pluck('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get("/sla-policies/{$this->policy->id}")->assertRedirect('/login');
});

test('users without sla.manage get a neutral access denied page without policy data', function (string $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->get("/sla-policies/{$this->policy->id}")
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->missing('policy')
            ->missing('history'));
})->with(['requester', 'technician', 'manager']);

test('audit.view alone does not grant access to the policy', function () {
    $this->actingAs(slaPolicyViewerWith('audit.view'))->get("/sla-policies/{$this->policy->id}")->assertForbidden();
});

test('inactive administrators are denied', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())
        ->get("/sla-policies/{$this->policy->id}")
        ->assertForbidden();
});

test('unauthorized users cannot tell which identifiers exist', function () {
    $user = User::factory()->withRole('technician')->create();

    $this->actingAs($user)->get('/sla-policies/999999')->assertForbidden();
});

test('missing policies return not found to authorized operators', function () {
    $this->actingAs($this->admin)->get('/sla-policies/999999')->assertNotFound();
});

test('non numeric identifiers do not match the route', function () {
    $this->actingAs($this->admin)->get('/sla-policies/abc')->assertNotFound();
});

/* ---------- Dados ---------- */

test('authorized users receive the real data of the policy', function () {
    $this->actingAs($this->admin)
        ->get("/sla-policies/{$this->policy->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SlaPolicies/Show')
            ->where('policy', [
                'id' => $this->policy->id,
                'priority' => 'Alta',
                'is_active' => true,
                'first_response_minutes' => 60,
                'resolution_minutes' => 480,
                'created_at' => $this->policy->created_at->toIso8601String(),
                'updated_at' => $this->policy->updated_at->toIso8601String(),
            ])
            ->where('activeSibling', null)
            ->where('can.edit', true));
});

test('an inactive policy points to the active policy of its priority', function () {
    $inactive = SlaPolicy::factory()->inactive()->create(['priority' => 'high', 'first_response_minutes' => 15, 'resolution_minutes' => 90]);

    $this->actingAs($this->admin)
        ->get("/sla-policies/{$inactive->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('policy.is_active', false)
            ->where('policy.first_response_minutes', 15)
            ->where('activeSibling', ['id' => $this->policy->id, 'first_response_minutes' => 60, 'resolution_minutes' => 480]));
});

test('an inactive policy whose priority has no active one reports no sibling', function () {
    $inactive = SlaPolicy::factory()->inactive()->create(['priority' => 'low']);

    $this->actingAs($this->admin)
        ->get("/sla-policies/{$inactive->id}")
        ->assertInertia(fn (Assert $page) => $page->where('activeSibling', null));
});

test('the edit action follows the update permission', function () {
    $this->actingAs(slaPolicyViewerWith('sla.manage'))
        ->get("/sla-policies/{$this->policy->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.edit', true));
});

/* ---------- Histórico ---------- */

test('the history lists the audit events with author, newest first', function () {
    $this->actingAs($this->admin)->post('/sla-policies', [
        'priority' => 'low', 'first_response_minutes' => '30', 'resolution_minutes' => '240',
        'status' => 'active', 'submission_token' => (string) Str::uuid(),
    ]);
    $created = SlaPolicy::where('priority', 'low')->sole();
    $this->travel(1)->minutes();
    $this->actingAs($this->admin)->patch("/sla-policies/{$created->id}", [
        'first_response_minutes' => '30', 'resolution_minutes' => '300', 'status' => 'inactive', 'version' => $created->editVersion(),
    ])->assertSessionHasNoErrors();

    $history = $this->actingAs($this->admin)->get("/sla-policies/{$created->id}")->viewData('page')['props']['history'];

    expect(collect($history)->pluck('event')->all())->toBe(['sla_policy_deactivated', 'sla_policy_updated', 'sla_policy_created'])
        ->and(collect($history)->pluck('author')->unique()->all())->toBe([$this->admin->name])
        ->and($history[0]['title'])->toBe('Política de SLA inativada')
        ->and($history[0]['changes'])->toBe([['field' => 'is_active', 'from' => true, 'to' => false]])
        ->and($history[1]['changes'])->toBe([['field' => 'resolution_minutes', 'from' => 240, 'to' => 300]])
        ->and($history[2]['changes'])->toBe([
            ['field' => 'first_response_minutes', 'from' => null, 'to' => 30],
            ['field' => 'resolution_minutes', 'from' => null, 'to' => 240],
            ['field' => 'is_active', 'from' => null, 'to' => true],
        ]);
});

test('the history only includes events of the consulted policy', function () {
    $other = SlaPolicy::factory()->create(['priority' => 'low']);
    $this->actingAs($this->admin)->patch("/sla-policies/{$other->id}", [
        'first_response_minutes' => '5', 'resolution_minutes' => '50', 'status' => 'active', 'version' => $other->editVersion(),
    ]);

    $this->actingAs($this->admin)
        ->get("/sla-policies/{$this->policy->id}")
        ->assertInertia(fn (Assert $page) => $page->where('history', []));
});

test('without audit.view the history is not sent', function () {
    $this->actingAs($this->admin)->patch("/sla-policies/{$this->policy->id}", [
        'first_response_minutes' => '5', 'resolution_minutes' => '50', 'status' => 'active', 'version' => $this->policy->editVersion(),
    ]);

    $this->actingAs(slaPolicyViewerWith('sla.manage'))
        ->get("/sla-policies/{$this->policy->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('history', null));

    $this->actingAs(slaPolicyViewerWith('sla.manage', 'audit.view'))
        ->get("/sla-policies/{$this->policy->id}")
        ->assertInertia(fn (Assert $page) => $page->has('history', 1));
});

test('authors are loaded without one query per event', function () {
    foreach (range(1, 5) as $minutes) {
        $current = $this->policy->fresh();
        $this->actingAs(User::factory()->withRole('admin')->create())->patch("/sla-policies/{$current->id}", [
            'first_response_minutes' => (string) (60 + $minutes), 'resolution_minutes' => '480', 'status' => 'active', 'version' => $current->editVersion(),
        ]);
    }

    DB::enableQueryLog();
    $this->actingAs($this->admin)->get("/sla-policies/{$this->policy->id}")->assertOk();
    $userQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "users"'))->count();

    // Sessão autenticada + um único carregamento dos autores.
    expect($userQueries)->toBeLessThanOrEqual(2);
});

/* ---------- Integridade ---------- */

test('consulting the policy does not modify it, other policies or tickets', function () {
    $other = SlaPolicy::factory()->inactive()->create(['priority' => 'high']);
    $ticket = Ticket::factory()->create(['priority' => 'high']);
    $snapshots = [$this->policy->fresh()->getAttributes(), $other->fresh()->getAttributes(), $ticket->fresh()->getAttributes()];
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)->get("/sla-policies/{$this->policy->id}")->assertOk();
    $this->actingAs($this->admin)->get("/sla-policies/{$other->id}")->assertOk();

    expect([$this->policy->fresh()->getAttributes(), $other->fresh()->getAttributes(), $ticket->fresh()->getAttributes()])
        ->toBe($snapshots)
        ->and(AuditLog::count())->toBe(0);
});
