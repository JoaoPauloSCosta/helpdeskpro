<?php

use App\Actions\SlaPolicies\CreateSlaPolicy;
use App\Enums\AdminEvent;
use App\Enums\TicketPriority;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
});

/** Dados válidos para o cadastro, com ajustes por teste. */
function newSlaPolicyData(array $overrides = []): array
{
    return [
        'priority' => 'high',
        'first_response_minutes' => '60',
        'resolution_minutes' => '480',
        'status' => 'active',
        'submission_token' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/** Papel personalizado só com a permissão informada. */
function slaPolicyCreatorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Só {$permission}", 'slug' => 'sla-creator-'.str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/sla-policies/create')->assertRedirect('/login');
    $this->post('/sla-policies', newSlaPolicyData())->assertRedirect('/login');

    expect(SlaPolicy::count())->toBe(0);
});

test('users without sla.manage can neither open the form nor create', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get('/sla-policies/create')->assertForbidden();
    $this->actingAs($user)->post('/sla-policies', newSlaPolicyData())->assertForbidden();

    expect(SlaPolicy::count())->toBe(0)->and(AuditLog::count())->toBe(0);
})->with(['requester', 'technician', 'manager']);

test('other permissions do not allow creating policies', function (string $permission) {
    $this->actingAs(slaPolicyCreatorWithOnly($permission))->post('/sla-policies', newSlaPolicyData())->assertForbidden();

    expect(SlaPolicy::count())->toBe(0);
})->with(['roles.manage', 'categories.manage', 'tickets.change_priority']);

test('inactive administrators cannot create policies', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())
        ->post('/sla-policies', newSlaPolicyData())
        ->assertForbidden();

    expect(SlaPolicy::count())->toBe(0);
});

test('a permission granted to a custom role is enough', function () {
    $this->actingAs(slaPolicyCreatorWithOnly('sla.manage'))
        ->post('/sla-policies', newSlaPolicyData())
        ->assertSessionHasNoErrors();

    expect(SlaPolicy::count())->toBe(1);
});

/* ---------- Formulário ---------- */

test('the form receives the real priorities, statuses, limits and a token, without default targets', function () {
    SlaPolicy::factory()->create(['priority' => 'critical']);
    SlaPolicy::factory()->inactive()->create(['priority' => 'low']);

    $this->actingAs($this->admin)
        ->get('/sla-policies/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SlaPolicies/Create')
            ->where('priorities', [
                ['value' => 'low', 'label' => 'Baixa', 'has_active' => false],
                ['value' => 'medium', 'label' => 'Média', 'has_active' => false],
                ['value' => 'high', 'label' => 'Alta', 'has_active' => false],
                ['value' => 'critical', 'label' => 'Crítica', 'has_active' => true],
            ])
            ->where('statuses', [
                ['value' => 'active', 'label' => 'Ativa'],
                ['value' => 'inactive', 'label' => 'Inativa'],
            ])
            ->where('limits', ['min' => 1, 'max' => 525600])
            ->where('submissionToken', fn (string $token) => Str::isUuid($token))
            ->missing('values'));
});

/* ---------- Criação ---------- */

test('authorized users create a policy and return to the filtered listing', function () {
    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData())
        ->assertRedirect(route('sla-policies.index', ['priority' => 'high']))
        ->assertSessionHas('success', 'Política de SLA da prioridade Alta cadastrada ativa.');

    $policy = SlaPolicy::sole();

    expect($policy->priority)->toBe(TicketPriority::High)
        ->and($policy->first_response_minutes)->toBe(60)
        ->and($policy->resolution_minutes)->toBe(480)
        ->and($policy->is_active)->toBeTrue();
});

test('policies can be created as inactive', function () {
    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData(['status' => 'inactive']))
        ->assertSessionHas('success', 'Política de SLA da prioridade Alta cadastrada como inativa.');

    expect(SlaPolicy::sole()->is_active)->toBeFalse();
});

test('fields outside the form are not persisted', function () {
    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData([
            'id' => 9999,
            'is_active' => false,
            'active_priority' => 'low',
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ]))
        ->assertSessionHasNoErrors();

    $policy = SlaPolicy::sole();

    expect($policy->id)->not->toBe(9999)
        ->and($policy->is_active)->toBeTrue()
        ->and($policy->getAttribute('active_priority'))->toBe('high')
        ->and($policy->created_at->year)->not->toBe(2000);
});

/* ---------- Validação ---------- */

test('invalid values are rejected without persisting anything', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData($overrides))
        ->assertSessionHasErrors($field);

    expect(SlaPolicy::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
})->with([
    'missing priority' => [['priority' => ''], 'priority'],
    'unknown priority' => [['priority' => 'urgent'], 'priority'],
    'priority label instead of value' => [['priority' => 'Alta'], 'priority'],
    'priority as array' => [['priority' => ['high']], 'priority'],
    'missing first response' => [['first_response_minutes' => ''], 'first_response_minutes'],
    'zero first response' => [['first_response_minutes' => '0'], 'first_response_minutes'],
    'negative first response' => [['first_response_minutes' => '-30'], 'first_response_minutes'],
    'decimal first response' => [['first_response_minutes' => '1.5'], 'first_response_minutes'],
    'text first response' => [['first_response_minutes' => '1h'], 'first_response_minutes'],
    'first response too long' => [['first_response_minutes' => '525601'], 'first_response_minutes'],
    'missing resolution' => [['resolution_minutes' => ''], 'resolution_minutes'],
    'zero resolution' => [['resolution_minutes' => '0'], 'resolution_minutes'],
    'negative resolution' => [['resolution_minutes' => '-1'], 'resolution_minutes'],
    'exponent resolution' => [['resolution_minutes' => '1e3'], 'resolution_minutes'],
    'resolution as array' => [['resolution_minutes' => ['480']], 'resolution_minutes'],
    'resolution too long' => [['resolution_minutes' => '9999999999'], 'resolution_minutes'],
    'missing status' => [['status' => ''], 'status'],
    'unknown status' => [['status' => 'draft'], 'status'],
    'missing token' => [['submission_token' => ''], 'submission_token'],
    'invalid token' => [['submission_token' => 'abc'], 'submission_token'],
]);

test('the limits accept values at the boundary', function () {
    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData(['first_response_minutes' => '1', 'resolution_minutes' => '525600']))
        ->assertSessionHasNoErrors();

    expect(SlaPolicy::sole()->only(['first_response_minutes', 'resolution_minutes']))
        ->toBe(['first_response_minutes' => 1, 'resolution_minutes' => 525600]);
});

test('the resolution target cannot be shorter than the first response target', function () {
    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData(['first_response_minutes' => '30', 'resolution_minutes' => '29']))
        ->assertSessionHasErrors(['resolution_minutes' => 'A meta de resolução não pode ser menor que a meta de primeira resposta.']);

    expect(SlaPolicy::count())->toBe(0);

    // Metas iguais são aceitas.
    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData(['first_response_minutes' => '30', 'resolution_minutes' => '30']))
        ->assertSessionHasNoErrors();
});

test('validation messages are in Portuguese', function () {
    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData(['resolution_minutes' => '']))
        ->assertSessionHasErrors(['resolution_minutes' => 'O campo meta de resolução é obrigatório.']);
});

/* ---------- Uma política ativa por prioridade ---------- */

test('a second active policy for the same priority is rejected', function () {
    $existing = SlaPolicy::factory()->create(['priority' => 'high']);

    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData())
        ->assertSessionHasErrors(['status' => 'Já existe uma política ativa para a prioridade Alta. Cadastre esta como inativa para manter uma única política aplicável.']);

    expect(SlaPolicy::count())->toBe(1)
        ->and($existing->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::count())->toBe(0);
});

test('an inactive policy can be added to a priority that already has an active one', function () {
    SlaPolicy::factory()->create(['priority' => 'high']);

    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData(['status' => 'inactive']))
        ->assertSessionHasNoErrors();

    expect(SlaPolicy::where('priority', 'high')->count())->toBe(2)
        ->and(SlaPolicy::where('priority', 'high')->where('is_active', true)->count())->toBe(1);
});

test('inactive policies do not block a new active one', function () {
    SlaPolicy::factory()->inactive()->count(2)->create(['priority' => 'high']);

    $this->actingAs($this->admin)->post('/sla-policies', newSlaPolicyData())->assertSessionHasNoErrors();

    expect(SlaPolicy::where('priority', 'high')->where('is_active', true)->count())->toBe(1);
});

test('the database rejects a second active policy even if validation is bypassed', function () {
    SlaPolicy::factory()->create(['priority' => 'medium']);

    expect(fn () => SlaPolicy::factory()->create(['priority' => 'medium']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a concurrent active policy turns into a validation error, not a server error', function () {
    // Simula outra política ativa gravada entre a validação e a gravação.
    $action = new class extends CreateSlaPolicy
    {
        public function __invoke(User $operator, array $data): SlaPolicy
        {
            DB::table('sla_policies')->insert([
                'priority' => 'high', 'first_response_minutes' => 10, 'resolution_minutes' => 20,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return parent::__invoke($operator, $data);
        }
    };
    $this->app->instance(CreateSlaPolicy::class, $action);

    $this->actingAs($this->admin)
        ->post('/sla-policies', newSlaPolicyData())
        ->assertSessionHasErrors('status');

    expect(SlaPolicy::count())->toBe(1)
        ->and(SlaPolicy::sole()->first_response_minutes)->toBe(10)
        ->and(AuditLog::count())->toBe(0);
});

/* ---------- Envio repetido ---------- */

test('repeating the same submission does not create a duplicate', function () {
    $data = newSlaPolicyData(['status' => 'inactive']);

    $first = $this->actingAs($this->admin)->post('/sla-policies', $data);
    $second = $this->actingAs($this->admin)->post('/sla-policies', $data);

    expect(SlaPolicy::count())->toBe(1)
        ->and(AuditLog::count())->toBe(1);
    $second->assertRedirect($first->headers->get('Location'));
});

/* ---------- Auditoria, atomicidade e integridade ---------- */

test('the creation is audited with the operator', function () {
    $this->actingAs($this->admin)->post('/sla-policies', newSlaPolicyData());

    $policy = SlaPolicy::sole();
    $log = AuditLog::sole();

    expect($log->event_type)->toBe(AdminEvent::SlaPolicyCreated)
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->auditable->is($policy))->toBeTrue()
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBe([
            'priority' => 'high',
            'first_response_minutes' => 60,
            'resolution_minutes' => 480,
            'is_active' => true,
        ]);
});

test('a failure while auditing leaves no policy and allows a retry', function () {
    $data = newSlaPolicyData();
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->admin)->post('/sla-policies', $data))
        ->toThrow(RuntimeException::class, 'falha simulada');

    expect(SlaPolicy::count())->toBe(0);

    // O token volta a valer e a prioridade continua livre: a nova tentativa grava normalmente.
    AuditLog::flushEventListeners();
    $this->actingAs($this->admin)->post('/sla-policies', $data)->assertSessionHasNoErrors();

    expect(SlaPolicy::count())->toBe(1);
});

test('creating a policy does not change existing policies or tickets', function () {
    $active = SlaPolicy::factory()->create(['priority' => 'low']);
    $inactive = SlaPolicy::factory()->inactive()->create(['priority' => 'high']);
    $ticket = Ticket::factory()->create(['priority' => 'high']);
    $snapshots = [$active->fresh()->getAttributes(), $inactive->fresh()->getAttributes(), $ticket->fresh()->getAttributes()];
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)->post('/sla-policies', newSlaPolicyData())->assertSessionHasNoErrors();

    expect(SlaPolicy::count())->toBe(3)
        ->and([$active->fresh()->getAttributes(), $inactive->fresh()->getAttributes(), $ticket->fresh()->getAttributes()])
        ->toBe($snapshots);
});
