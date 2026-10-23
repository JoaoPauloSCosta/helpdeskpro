<?php

use App\Enums\AdminEvent;
use App\Enums\TicketPriority;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
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

/** Dados válidos para a edição da política, com a versão atual e ajustes por teste. */
function slaPolicyEditData(SlaPolicy $policy, array $overrides = []): array
{
    return [
        'first_response_minutes' => (string) $policy->first_response_minutes,
        'resolution_minutes' => (string) $policy->resolution_minutes,
        'status' => $policy->is_active ? 'active' : 'inactive',
        'version' => $policy->editVersion(),
        ...$overrides,
    ];
}

/** Papel personalizado só com a permissão informada. */
function slaPolicyEditorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Edita {$permission}", 'slug' => 'sla-editor-'.str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get("/sla-policies/{$this->policy->id}/edit")->assertRedirect('/login');
    $this->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy))->assertRedirect('/login');
});

test('the resolution target cannot be edited to less than the first response target', function () {
    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['resolution_minutes' => '59']))
        ->assertSessionHasErrors(['resolution_minutes' => 'A meta de resolução não pode ser menor que a meta de primeira resposta.']);

    expect($this->policy->fresh()->resolution_minutes)->toBe(480);

    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['resolution_minutes' => '60']))
        ->assertSessionHasNoErrors();
});

test('users without sla.manage can neither view nor edit', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get("/sla-policies/{$this->policy->id}/edit")->assertForbidden();
    $this->actingAs($user)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['resolution_minutes' => '10', 'status' => 'inactive']))
        ->assertForbidden();

    expect($this->policy->fresh()->resolution_minutes)->toBe(480)
        ->and($this->policy->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::count())->toBe(0);
})->with(['requester', 'technician', 'manager']);

test('other permissions do not allow editing policies, not even the status', function (string $permission) {
    $this->actingAs(slaPolicyEditorWithOnly($permission))
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['status' => 'inactive']))
        ->assertForbidden();

    expect($this->policy->fresh()->is_active)->toBeTrue();
})->with(['roles.manage', 'categories.manage', 'tickets.change_priority']);

test('a custom role with sla.manage can edit', function () {
    $this->actingAs(slaPolicyEditorWithOnly('sla.manage'))
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['resolution_minutes' => '600']))
        ->assertSessionHasNoErrors();

    expect($this->policy->fresh()->resolution_minutes)->toBe(600);
});

test('inactive administrators are denied', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())
        ->get("/sla-policies/{$this->policy->id}/edit")
        ->assertForbidden();
});

test('unauthorized users cannot tell which identifiers exist', function () {
    $user = User::factory()->withRole('technician')->create();

    $this->actingAs($user)->get("/sla-policies/{$this->policy->id}/edit")->assertForbidden();
    $this->actingAs($user)->get('/sla-policies/999999/edit')->assertForbidden();
    $this->actingAs($user)->patch('/sla-policies/999999', slaPolicyEditData($this->policy))->assertForbidden();
});

test('missing policies return not found to authorized operators', function () {
    $this->actingAs($this->admin)->get('/sla-policies/999999/edit')->assertNotFound();
    $this->actingAs($this->admin)->patch('/sla-policies/999999', slaPolicyEditData($this->policy))->assertNotFound();
});

test('non numeric identifiers do not match the route', function () {
    $this->actingAs($this->admin)->get('/sla-policies/abc/edit')->assertNotFound();
});

/* ---------- Formulário ---------- */

test('the form receives the real data of the selected policy', function () {
    $this->actingAs($this->admin)
        ->get("/sla-policies/{$this->policy->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SlaPolicies/Edit')
            ->where('policy.id', $this->policy->id)
            ->where('policy.priority', 'Alta')
            ->where('policy.is_active', true)
            ->where('policy.first_response_minutes', 60)
            ->where('policy.resolution_minutes', 480)
            ->where('policy.other_active', false)
            ->where('values', ['first_response_minutes' => '60', 'resolution_minutes' => '480', 'status' => 'active'])
            ->where('limits', ['min' => 1, 'max' => 525600])
            ->where('version', $this->policy->editVersion()));
});

test('an inactive policy reports when another active one blocks its activation', function () {
    $inactive = SlaPolicy::factory()->inactive()->create(['priority' => 'high']);

    $this->actingAs($this->admin)
        ->get("/sla-policies/{$inactive->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('policy.is_active', false)
            ->where('policy.other_active', true)
            ->where('values.status', 'inactive'));
});

test('the listing offers the edit action', function () {
    $this->actingAs($this->admin)
        ->get('/sla-policies')
        ->assertInertia(fn (Assert $page) => $page->where('policies.data.0.can_edit', true));
});

/* ---------- Atualização ---------- */

test('valid target changes are persisted and the operator returns to the listing', function () {
    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, [
            'first_response_minutes' => '30',
            'resolution_minutes' => ' 240 ',
        ]))
        ->assertRedirect(route('sla-policies.index', ['priority' => 'high']))
        ->assertSessionHas('success', 'Metas da política de SLA da prioridade Alta atualizadas.');

    $fresh = $this->policy->fresh();

    expect($fresh->first_response_minutes)->toBe(30)
        ->and($fresh->resolution_minutes)->toBe(240)
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->priority)->toBe(TicketPriority::High);
});

test('unchanged targets are preserved when only one changes', function () {
    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['resolution_minutes' => '600']))
        ->assertSessionHasNoErrors();

    expect($this->policy->fresh()->only(['first_response_minutes', 'resolution_minutes']))
        ->toBe(['first_response_minutes' => 60, 'resolution_minutes' => 600]);
});

test('saving without changes records nothing', function () {
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['resolution_minutes' => ' 480 ']))
        ->assertRedirect(route('sla-policies.edit', $this->policy))
        ->assertSessionHas('info', 'Nenhuma alteração para salvar.');

    expect($this->policy->fresh()->updated_at->equalTo($this->policy->updated_at))->toBeTrue()
        ->and(AuditLog::count())->toBe(0);
});

test('a stale form is rejected as a conflict', function () {
    $stale = slaPolicyEditData($this->policy, ['resolution_minutes' => '900']);
    $this->policy->forceFill(['resolution_minutes' => 300])->save();

    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", $stale)
        ->assertSessionHasErrors('conflict');

    expect($this->policy->fresh()->resolution_minutes)->toBe(300)
        ->and(AuditLog::count())->toBe(0);
});

test('fields outside the form are not changed', function () {
    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, [
            'resolution_minutes' => '600',
            'id' => 9999,
            'priority' => 'low',
            'is_active' => false,
            'active_priority' => 'low',
            'created_at' => '2000-01-01 00:00:00',
        ]))
        ->assertSessionHasNoErrors();

    $fresh = $this->policy->fresh();

    expect($fresh->id)->toBe($this->policy->id)
        ->and($fresh->priority)->toBe(TicketPriority::High)
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->getAttribute('active_priority'))->toBe('high')
        ->and($fresh->created_at->year)->not->toBe(2000)
        ->and(SlaPolicy::count())->toBe(1);
});

test('invalid values are rejected without partial changes', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['first_response_minutes' => '45', ...$overrides]))
        ->assertSessionHasErrors($field);

    expect($this->policy->fresh()->only(['first_response_minutes', 'resolution_minutes', 'is_active']))
        ->toBe(['first_response_minutes' => 60, 'resolution_minutes' => 480, 'is_active' => true])
        ->and(AuditLog::count())->toBe(0);
})->with([
    'missing resolution' => [['resolution_minutes' => ''], 'resolution_minutes'],
    'zero resolution' => [['resolution_minutes' => '0'], 'resolution_minutes'],
    'negative resolution' => [['resolution_minutes' => '-10'], 'resolution_minutes'],
    'decimal resolution' => [['resolution_minutes' => '2.5'], 'resolution_minutes'],
    'text resolution' => [['resolution_minutes' => '8h'], 'resolution_minutes'],
    'resolution too long' => [['resolution_minutes' => '525601'], 'resolution_minutes'],
    'first response as array' => [['first_response_minutes' => ['45']], 'first_response_minutes'],
    'negative first response' => [['first_response_minutes' => '-1'], 'first_response_minutes'],
    'missing status' => [['status' => ''], 'status'],
    'unknown status' => [['status' => 'archived'], 'status'],
    'missing version' => [['version' => ''], 'version'],
]);

test('validation messages are in Portuguese', function () {
    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['first_response_minutes' => 'abc']))
        ->assertSessionHasErrors(['first_response_minutes' => 'Informe a meta de primeira resposta em minutos inteiros.']);
});

/* ---------- Situação ---------- */

test('deactivation keeps the policy and is audited separately', function () {
    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['status' => 'inactive']))
        ->assertSessionHas('success', 'Política de SLA da prioridade Alta inativada.');

    $fresh = $this->policy->fresh();
    $log = AuditLog::sole();

    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->only(['first_response_minutes', 'resolution_minutes']))->toBe(['first_response_minutes' => 60, 'resolution_minutes' => 480])
        ->and($log->event_type)->toBe(AdminEvent::SlaPolicyDeactivated)
        ->and($log->old_values)->toBe(['priority' => 'high', 'is_active' => true])
        ->and($log->new_values)->toBe(['priority' => 'high', 'is_active' => false]);
});

test('an inactive policy can be activated when its priority has no active one', function () {
    $this->policy->forceFill(['is_active' => false])->save();
    $policy = $this->policy->fresh();

    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$policy->id}", slaPolicyEditData($policy, ['status' => 'active']))
        ->assertSessionHas('success', 'Política de SLA da prioridade Alta ativada.');

    expect($policy->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::sole()->event_type)->toBe(AdminEvent::SlaPolicyActivated);
});

test('activation is rejected while another policy of the priority is active', function () {
    $inactive = SlaPolicy::factory()->inactive()->create(['priority' => 'high']);

    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$inactive->id}", slaPolicyEditData($inactive, ['status' => 'active', 'resolution_minutes' => '100']))
        ->assertSessionHasErrors(['status' => 'Já existe outra política ativa para a prioridade Alta. Inative-a antes de ativar esta, para manter uma única política aplicável.']);

    expect($inactive->fresh()->is_active)->toBeFalse()
        ->and($inactive->fresh()->resolution_minutes)->not->toBe(100)
        ->and($this->policy->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::count())->toBe(0);
});

test('the active policy can keep its status while its targets change', function () {
    SlaPolicy::factory()->inactive()->create(['priority' => 'high']);

    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['first_response_minutes' => '15']))
        ->assertSessionHasNoErrors();

    expect($this->policy->fresh()->first_response_minutes)->toBe(15);
});

test('a concurrent activation turns into a validation error, not a server error', function () {
    $this->policy->forceFill(['is_active' => false])->save();
    $policy = $this->policy->fresh();
    $data = slaPolicyEditData($policy, ['status' => 'active']);

    // Outra política ativa gravada depois da validação, durante a gravação desta.
    SlaPolicy::saving(function (SlaPolicy $saving) use ($policy) {
        if ($saving->id === $policy->id) {
            SlaPolicy::withoutEvents(fn () => SlaPolicy::factory()->create(['priority' => 'high', 'resolution_minutes' => 1]));
        }
    });

    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$policy->id}", $data)
        ->assertSessionHasErrors('status');

    SlaPolicy::flushEventListeners();

    expect($policy->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::count())->toBe(0);
});

/* ---------- Auditoria, atomicidade e integridade ---------- */

test('changes are audited with the operator and previous and new values', function () {
    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['resolution_minutes' => '720', 'status' => 'inactive']));

    $logs = AuditLog::orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->event_type)->toBe(AdminEvent::SlaPolicyUpdated)
        ->and($logs[0]->user_id)->toBe($this->admin->id)
        ->and($logs[0]->auditable->is($this->policy))->toBeTrue()
        ->and($logs[0]->old_values)->toBe(['priority' => 'high', 'resolution_minutes' => 480])
        ->and($logs[0]->new_values)->toBe(['priority' => 'high', 'resolution_minutes' => 720])
        ->and($logs[1]->event_type)->toBe(AdminEvent::SlaPolicyDeactivated);
});

test('a failure while auditing leaves no partial change', function () {
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['resolution_minutes' => '720', 'status' => 'inactive'])))
        ->toThrow(RuntimeException::class, 'falha simulada');

    expect($this->policy->fresh()->only(['resolution_minutes', 'is_active']))->toBe(['resolution_minutes' => 480, 'is_active' => true]);
});

test('editing one policy does not change other policies or tickets', function () {
    $other = SlaPolicy::factory()->create(['priority' => 'low']);
    $inactive = SlaPolicy::factory()->inactive()->create(['priority' => 'high']);
    $ticket = Ticket::factory()->create(['priority' => 'high']);
    $snapshots = [$other->fresh()->getAttributes(), $inactive->fresh()->getAttributes(), $ticket->fresh()->getAttributes()];
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)
        ->patch("/sla-policies/{$this->policy->id}", slaPolicyEditData($this->policy, ['resolution_minutes' => '60', 'status' => 'inactive']))
        ->assertSessionHasNoErrors();

    expect([$other->fresh()->getAttributes(), $inactive->fresh()->getAttributes(), $ticket->fresh()->getAttributes()])
        ->toBe($snapshots)
        ->and(SlaPolicy::count())->toBe(3);
});
