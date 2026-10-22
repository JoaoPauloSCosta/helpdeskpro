<?php

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketSlaPause;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Marina Souza']);
    $this->requester = User::factory()->withRole('requester')->create();
    $this->department = Department::factory()->create(['name' => 'Infraestrutura']);

    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'assigned_to' => $this->tech->id,
        'requester_id' => $this->requester->id,
        'title' => 'Impressora sem toner',
        'priority' => TicketPriority::High,
    ]);
});

function pendingUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}/pending";
}

/** Registra a pendência; sem `$expected`, usa o status gravado agora (o que a tela mostraria). */
function postPending(User $user, Ticket $ticket, array $data = [], ?string $expected = null)
{
    return test()->actingAs($user)->post(pendingUrl($ticket), [
        'pause_status' => 'pending',
        'reason' => 'Aguardando janela de manutenção do servidor.',
        'expected_status' => $expected ?? $ticket->fresh()->status->value,
        ...$data,
    ]);
}

/** Nada foi gravado: status, pendência e auditoria intactos. */
function expectNothingRecorded(Ticket $ticket, TicketStatus $status = TicketStatus::InProgress): void
{
    expect($ticket->fresh()->status)->toBe($status)
        ->and(TicketSlaPause::count())->toBe(0)
        ->and(TicketHistory::count())->toBe(0);
}

/* ---------- Acesso e permissão ---------- */

test('guests cannot register a pending', function () {
    $this->post(pendingUrl($this->ticket), ['pause_status' => 'pending', 'reason' => 'x', 'expected_status' => 'in_progress'])
        ->assertRedirect('/login');

    expectNothingRecorded($this->ticket);
});

test('users without tickets.change_status cannot register a pending', function (string $role) {
    $user = $role === 'requester' ? $this->requester : User::factory()->withRole($role)->create();

    postPending($user, $this->ticket)->assertForbidden();

    expectNothingRecorded($this->ticket);
})->with([
    'solicitante dono do chamado' => 'requester',
    'administrador com visibilidade global' => 'admin', // RN-03: ver não é alterar
]);

test('technicians cannot register a pending on tickets outside their access', function () {
    $other = User::factory()->withRole('technician')->create();

    postPending($other, $this->ticket)->assertForbidden();

    expectNothingRecorded($this->ticket);
});

test('inactive users are rejected', function () {
    $this->tech->forceFill(['is_active' => false])->save();

    postPending($this->tech, $this->ticket)->assertForbidden();

    expectNothingRecorded($this->ticket);
});

test('an unknown ticket returns not found', function () {
    $this->actingAs($this->tech)
        ->post('/tickets/HD-ZZZZZZZZ/pending', ['pause_status' => 'pending', 'reason' => 'x', 'expected_status' => 'in_progress'])
        ->assertNotFound();
});

/* ---------- Registro válido ---------- */

test('the assigned technician registers each type of pending', function (TicketStatus $type) {
    postPending($this->tech, $this->ticket, ['pause_status' => $type->value])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number))
        ->assertSessionHas('success', "Pendência registrada. Status alterado para {$type->label()}.");

    $ticket = $this->ticket->fresh();
    $pause = TicketSlaPause::sole();

    expect($ticket->status)->toBe($type)
        ->and($pause->pause_status)->toBe($type)
        ->and($pause->ticket_id)->toBe($ticket->id)
        ->and($pause->reason)->toBe('Aguardando janela de manutenção do servidor.')
        ->and($pause->ended_at)->toBeNull()
        ->and($pause->duration_seconds)->toBeNull()
        ->and($ticket->activeSlaPause->is($pause))->toBeTrue()
        ->and($ticket->slaState()->value)->toBe('paused');
})->with(fn () => TicketStatus::paused());

test('a manager with global visibility may register a pending', function () {
    postPending($this->manager, $this->ticket)->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Pending)
        ->and(TicketSlaPause::sole()->created_by)->toBe($this->manager->id);
});

test('the specific fields of each type are stored', function () {
    $return = now()->addDays(3)->toDateString();

    postPending($this->tech, $this->ticket, [
        'pause_status' => 'pending_third_party',
        'supplier_name' => 'Fornecedor XPTO',
        'protocol_number' => 'INC-2026-0042',
        'responsible_name' => 'João do suporte XPTO',
        'expected_return_at' => $return,
    ])->assertSessionHasNoErrors();

    $pause = TicketSlaPause::sole();

    expect($pause->supplier_name)->toBe('Fornecedor XPTO')
        ->and($pause->protocol_number)->toBe('INC-2026-0042')
        ->and($pause->responsible_name)->toBe('João do suporte XPTO')
        ->and($pause->expected_return_at->toDateString())->toBe($return)
        ->and($pause->related_department_id)->toBeNull();
});

test('a pending on another department stores the related department', function () {
    postPending($this->tech, $this->ticket, [
        'pause_status' => 'pending_departments',
        'related_department_id' => (string) $this->department->id,
    ])->assertSessionHasNoErrors();

    expect(TicketSlaPause::sole()->related_department_id)->toBe($this->department->id);
});

test('only the reason is required: specific fields are optional', function (TicketStatus $type) {
    postPending($this->tech, $this->ticket, [
        'pause_status' => $type->value,
        ...array_fill_keys($type->pendingFields(), ''),
    ])->assertSessionHasNoErrors();

    $pause = TicketSlaPause::sole();

    foreach (['supplier_name', 'protocol_number', 'related_department_id', 'responsible_name', 'expected_return_at'] as $field) {
        expect($pause->{$field})->toBeNull();
    }
})->with(fn () => TicketStatus::paused());

/* ---------- Validação ---------- */

test('invalid or manipulated pending types are rejected', function (mixed $type) {
    postPending($this->tech, $this->ticket, ['pause_status' => $type])->assertSessionHasErrors('pause_status');

    expectNothingRecorded($this->ticket);
})->with([
    'status que não é de pausa' => 'resolved',
    'status atual' => 'in_progress',
    'aberto' => 'open',
    'encerrado' => 'closed',
    'inexistente' => 'archived',
    'rótulo em vez do valor' => 'Pendente',
    'vazio' => '',
    'nulo' => null,
    'lista' => [['pending']],
    'número' => 4,
]);

test('the reason is required and limited', function (mixed $reason) {
    postPending($this->tech, $this->ticket, ['reason' => $reason])->assertSessionHasErrors('reason');

    expectNothingRecorded($this->ticket);
})->with([
    'vazio' => '',
    'só espaços' => '   ',
    'nulo' => null,
    'lista' => [['motivo']],
    'longo demais' => fn () => str_repeat('a', TicketSlaPause::REASON_MAX_LENGTH + 1),
]);

test('fields that do not apply to the selected type are rejected', function (string $type, string $field, mixed $value) {
    postPending($this->tech, $this->ticket, ['pause_status' => $type, $field => $value])
        ->assertSessionHasErrors([$field => 'Este campo não se aplica ao tipo de pendência selecionado.']);

    expectNothingRecorded($this->ticket);
})->with([
    'fornecedor em pendente' => ['pending', 'supplier_name', 'XPTO'],
    'protocolo em aguardando solicitante' => ['awaiting_requester', 'protocol_number', '123'],
    'departamento em pendente terceiros' => ['pending_third_party', 'related_department_id', 1],
    'fornecedor em pendente departamentos' => ['pending_departments', 'supplier_name', 'XPTO'],
    'responsável em pendente' => ['pending', 'responsible_name', 'Fulano'],
]);

test('specific fields are validated', function (string $type, string $field, mixed $value) {
    postPending($this->tech, $this->ticket, ['pause_status' => $type, $field => $value])->assertSessionHasErrors($field);

    expectNothingRecorded($this->ticket);
})->with([
    'departamento inexistente' => ['pending_departments', 'related_department_id', 999],
    'departamento inválido' => ['pending_departments', 'related_department_id', 'abc'],
    'departamento inativo' => fn () => ['pending_departments', 'related_department_id', Department::factory()->create(['is_active' => false])->id],
    'previsão no passado' => fn () => ['pending', 'expected_return_at', now()->subDay()->toDateString()],
    'previsão em formato inválido' => ['pending', 'expected_return_at', '31/12/2026'],
    'previsão inexistente' => ['pending', 'expected_return_at', '2026-02-30'],
    'fornecedor longo demais' => ['pending_third_party', 'supplier_name', str_repeat('a', 256)],
    'protocolo longo demais' => ['pending_third_party', 'protocol_number', str_repeat('a', 256)],
    'protocolo como lista' => ['pending_third_party', 'protocol_number', ['123']],
]);

test('today is accepted as the expected return', function () {
    $this->freezeSecond();

    postPending($this->tech, $this->ticket, ['expected_return_at' => now()->toDateString()])->assertSessionHasNoErrors();

    expect(TicketSlaPause::sole()->expected_return_at->toDateString())->toBe(now()->toDateString());
});

test('the expected status is required and must be a known status', function (mixed $expected) {
    $this->actingAs($this->tech)
        ->post(pendingUrl($this->ticket), ['pause_status' => 'pending', 'reason' => 'x', 'expected_status' => $expected])
        ->assertSessionHasErrors('expected_status');

    expectNothingRecorded($this->ticket);
})->with([null, 'waiting']);

/* ---------- Estado do chamado ---------- */

test('a pending is only registered on tickets in progress', function (TicketStatus $status) {
    $this->ticket->forceFill(['status' => $status])->save();

    // O status esperado é o real: não é conflito, é estado incompatível.
    postPending($this->manager, $this->ticket)
        ->assertSessionHasErrors(['pause_status' => 'Este tipo de pendência não está disponível para o chamado no estado atual.']);

    expectNothingRecorded($this->ticket, $status);
})->with([
    'aberto' => TicketStatus::Open,
    'aguardando solicitante' => TicketStatus::AwaitingRequester,
    'pendente' => TicketStatus::Pending,
    'pendente terceiros' => TicketStatus::PendingThirdParty,
    'pendente departamentos' => TicketStatus::PendingDepartments,
    'resolvido' => TicketStatus::Resolved,
    'encerrado' => TicketStatus::Closed,
]);

/* ---------- Auditoria e integridade ---------- */

test('the history records the pending with author, reason, details and server time', function () {
    $this->freezeSecond();
    $return = now()->addWeek()->toDateString();

    postPending($this->tech, $this->ticket, [
        'pause_status' => 'pending_departments',
        'reason' => 'Depende da liberação de acesso pelo time de redes.',
        'related_department_id' => $this->department->id,
        'protocol_number' => 'REDE-77',
        'expected_return_at' => $return,
    ])->assertSessionHasNoErrors();

    $event = TicketHistory::sole();
    $pause = TicketSlaPause::sole();

    expect($event->event_type)->toBe(TicketEvent::PendingRegistered)
        ->and($event->ticket_id)->toBe($this->ticket->id)
        ->and($event->user_id)->toBe($this->tech->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue()
        ->and($event->old_values)->toBe(['status' => 'in_progress', 'label' => 'Em atendimento'])
        ->and($event->new_values)->toEqual([
            'status' => 'pending_departments',
            'label' => 'Pendente Departamentos',
            'reason' => 'Depende da liberação de acesso pelo time de redes.',
            'related_department_id' => $this->department->id,
            'protocol_number' => 'REDE-77',
            'expected_return_at' => $return,
            'related_department' => 'Infraestrutura',
            'sla_pause' => ['indicator' => 'resolution', 'started_at' => now()->toIso8601String()],
        ])
        ->and($pause->ticket_history_id)->toBe($event->id);
});

test('author and time always come from the server', function () {
    $this->freezeSecond();

    postPending($this->tech, $this->ticket, [
        'created_by' => $this->manager->id,
        'user_id' => $this->manager->id,
        'started_at' => '2020-01-01 00:00:00',
        'ended_at' => '2020-01-02 00:00:00',
        'duration_seconds' => 1,
        'created_at' => '2020-01-01 00:00:00',
        'ticket_history_id' => 999,
    ])->assertSessionHasNoErrors();

    $pause = TicketSlaPause::sole();

    expect($pause->created_by)->toBe($this->tech->id)
        ->and($pause->started_at->equalTo(now()))->toBeTrue()
        ->and($pause->ended_at)->toBeNull()
        ->and($pause->duration_seconds)->toBeNull()
        ->and($pause->ticket_history_id)->toBe(TicketHistory::sole()->id)
        ->and(TicketHistory::sole()->user_id)->toBe($this->tech->id)
        ->and($this->ticket->fresh()->updated_at->equalTo(now()))->toBeTrue();
});

test('registering a pending does not touch any other field of the ticket', function () {
    $other = User::factory()->withRole('technician')->create();
    $before = $this->ticket->fresh()->getAttributes();

    postPending($this->tech, $this->ticket, [
        'assigned_to' => $other->id,
        'requester_id' => $other->id,
        'department_id' => 999,
        'category_id' => 999,
        'priority' => 'low',
        'title' => 'Título manipulado',
        'status' => 'resolved',
        'ticket_number' => 'HD-AAAAAAAA',
        'resolution_due_at' => now()->addYear()->toDateTimeString(),
        'first_response_at' => now()->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $after = $this->ticket->fresh()->getAttributes();

    expect(Arr::except($after, ['status', 'updated_at']))->toEqual(Arr::except($before, ['status', 'updated_at']))
        ->and($after['status'])->toBe('pending');
});

test('a status changed by someone else is reported as a conflict and not overwritten', function () {
    // A tela foi aberta com "Em atendimento"; outra pessoa registrou uma pendência nesse meio-tempo.
    $this->ticket->forceFill(['status' => TicketStatus::AwaitingRequester])->save();

    postPending($this->tech, $this->ticket, ['pause_status' => 'pending_third_party'], expected: 'in_progress')
        ->assertSessionHasErrors('conflict')
        ->assertSessionMissing('success');

    expectNothingRecorded($this->ticket, TicketStatus::AwaitingRequester);
});

test('a repeated or concurrent submission creates a single pending', function () {
    postPending($this->tech, $this->ticket, expected: 'in_progress')->assertSessionHasNoErrors();
    postPending($this->manager, $this->ticket, ['pause_status' => 'pending_third_party'], expected: 'in_progress')
        ->assertSessionHasErrors('conflict');
    postPending($this->tech, $this->ticket, expected: 'in_progress')->assertSessionHasErrors('conflict');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Pending)
        ->and(TicketSlaPause::count())->toBe(1)
        ->and(TicketHistory::count())->toBe(1)
        ->and(TicketSlaPause::sole()->created_by)->toBe($this->tech->id);
});

test('an open pause blocks a new one even if the status is inconsistent', function () {
    $this->ticket->slaPauses()->forceCreate([
        'pause_status' => TicketStatus::Pending,
        'reason' => 'Pausa antiga não encerrada',
        'started_at' => now()->subHour(),
        'created_by' => $this->tech->id,
    ]);

    postPending($this->tech, $this->ticket)->assertSessionHasErrors('pause_status');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and(TicketSlaPause::count())->toBe(1)
        ->and(TicketHistory::count())->toBe(0);
});

test('the database rejects two open pauses for the same ticket', function () {
    $open = fn () => $this->ticket->slaPauses()->forceCreate([
        'pause_status' => TicketStatus::Pending,
        'reason' => 'Motivo',
        'started_at' => now(),
        'created_by' => $this->tech->id,
    ]);

    $open();

    expect($open)->toThrow(QueryException::class);
});

test('a failure while recording the pause leaves nothing behind', function () {
    TicketSlaPause::creating(fn () => throw new RuntimeException('falha simulada na pausa'));
    $this->withoutExceptionHandling();

    expect(fn () => postPending($this->tech, $this->ticket))->toThrow(RuntimeException::class, 'falha simulada na pausa');

    expectNothingRecorded($this->ticket);
});

test('a failure while recording the audit leaves nothing behind', function () {
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));
    $this->withoutExceptionHandling();

    expect(fn () => postPending($this->tech, $this->ticket))->toThrow(RuntimeException::class, 'falha simulada na auditoria');

    expectNothingRecorded($this->ticket);
});

/* ---------- SLA ---------- */

test('registering a pending keeps SLA deadlines, violations and first response', function () {
    $recorded = [
        'first_response_due_at' => now()->subHours(3)->startOfSecond(),
        'first_response_at' => now()->subHours(4)->startOfSecond(),
        'resolution_due_at' => now()->subHour()->startOfSecond(),
    ];
    $this->ticket->forceFill($recorded)->save();

    expect($this->ticket->fresh()->slaState()->value)->toBe('overdue');

    postPending($this->tech, $this->ticket)->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    // Sem motor de SLA (Etapa 7), nenhum prazo é recalculado e a pausa não esconde a violação gravada.
    foreach ($recorded as $column => $value) {
        expect($ticket->{$column}->equalTo($value))->toBeTrue();
    }
    expect($ticket->slaState()->value)->toBe('paused')
        ->and($ticket->firstResponseSlaState()->value)->toBe('met'); // RF-15.1: independe das pausas
});

test('resuming closes the open pause with a duration computed by the server', function () {
    $this->freezeSecond();
    postPending($this->tech, $this->ticket)->assertSessionHasNoErrors();
    $started = now()->copy();

    $this->travel(95)->minutes();

    $this->actingAs($this->tech)
        ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'in_progress', 'expected_status' => 'pending'])
        ->assertSessionHasNoErrors();

    $pause = TicketSlaPause::sole();
    $resume = TicketHistory::where('event_type', TicketEvent::StatusChanged)->sole();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($this->ticket->fresh()->activeSlaPause)->toBeNull()
        ->and($pause->started_at->equalTo($started))->toBeTrue()
        ->and($pause->ended_at->equalTo(now()))->toBeTrue()
        ->and($pause->duration_seconds)->toBe(95 * 60)
        ->and($resume->new_values['sla_pause'])->toBe([
            'indicator' => 'resolution',
            'started_at' => $started->toIso8601String(),
            'ended_at' => now()->toIso8601String(),
            'duration_seconds' => 95 * 60,
        ]);
});

test('multiple pause cycles create independent records that are never overwritten', function () {
    $this->freezeSecond();

    $cycle = function (string $type, int $minutes) {
        postPending($this->tech, $this->ticket, ['pause_status' => $type])->assertSessionHasNoErrors();
        $this->travel($minutes)->minutes();
        $this->actingAs($this->tech)
            ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'in_progress', 'expected_status' => $type])
            ->assertSessionHasNoErrors();
        $this->travel(10)->minutes();
    };

    $cycle('awaiting_requester', 30);
    $cycle('pending_third_party', 45);
    postPending($this->tech, $this->ticket, ['pause_status' => 'pending'])->assertSessionHasNoErrors();

    $pauses = TicketSlaPause::orderBy('id')->get();

    expect($pauses)->toHaveCount(3)
        ->and($pauses->pluck('pause_status')->all())->toBe([TicketStatus::AwaitingRequester, TicketStatus::PendingThirdParty, TicketStatus::Pending])
        ->and($pauses->pluck('duration_seconds')->all())->toBe([30 * 60, 45 * 60, null])
        ->and($pauses[0]->ended_at->lte($pauses[1]->started_at))->toBeTrue()
        ->and($pauses[1]->ended_at->lte($pauses[2]->started_at))->toBeTrue()
        ->and(TicketSlaPause::active()->count())->toBe(1);
});

test('a ticket paused before pendings were recorded can still be resumed', function () {
    $this->ticket->forceFill(['status' => TicketStatus::PendingThirdParty])->save();

    $this->actingAs($this->tech)
        ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'in_progress', 'expected_status' => 'pending_third_party'])
        ->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and(TicketHistory::sole()->new_values)->not->toHaveKey('sla_pause');
});

test('a failure while auditing the resume keeps the pause open', function () {
    postPending($this->tech, $this->ticket)->assertSessionHasNoErrors();
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->tech)
        ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'in_progress', 'expected_status' => 'pending']))
        ->toThrow(RuntimeException::class);

    $pause = TicketSlaPause::sole();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Pending)
        ->and($pause->ended_at)->toBeNull()
        ->and($pause->duration_seconds)->toBeNull();
});

/* ---------- Tela de detalhes ---------- */

test('read-only users receive neither the action nor the options', function (string $role) {
    $user = $role === 'requester' ? $this->requester : User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.registerPending', false)
            ->where('pending', null));
})->with(['requester', 'admin']);

test('the details page offers the pending types and fields the backend accepts', function () {
    Department::factory()->create(['name' => 'Arquivado', 'is_active' => false]);

    $this->actingAs($this->tech)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.registerPending', true)
            ->where('pending.current', 'in_progress')
            ->where('pending.types', fn ($types) => collect($types)->map(fn ($t) => [$t['value'], $t['fields']])->all() === [
                ['awaiting_requester', ['expected_return_at']],
                ['pending', ['expected_return_at']],
                ['pending_third_party', ['supplier_name', 'protocol_number', 'responsible_name', 'expected_return_at']],
                ['pending_departments', ['related_department_id', 'protocol_number', 'responsible_name', 'expected_return_at']],
            ])
            // Somente departamentos ativos.
            ->where('pending.departments', fn ($departments) => collect($departments)->pluck('label')->sort()->values()->all()
                === Department::where('is_active', true)->orderBy('name')->pluck('name')->all()
                && ! collect($departments)->contains('label', 'Arquivado')));
});

test('no pending is offered outside of in progress', function (TicketStatus $status) {
    $this->ticket->forceFill(['status' => $status])->save();

    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.registerPending', false)
            ->where('pending.types', []));
})->with([TicketStatus::Open, TicketStatus::Pending, TicketStatus::Resolved, TicketStatus::Closed]);

test('the active pending is shown to the support team only', function () {
    postPending($this->tech, $this->ticket, [
        'pause_status' => 'pending_third_party',
        'supplier_name' => 'Fornecedor XPTO',
        'expected_return_at' => now()->addDay()->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->tech)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.status', 'Pendente Terceiros')
            ->where('sla.resolution.state', 'paused')
            ->where('activePending.type', 'Pendente Terceiros')
            ->where('activePending.reason', 'Aguardando janela de manutenção do servidor.')
            ->where('activePending.supplier_name', 'Fornecedor XPTO')
            ->where('activePending.expected_return_at', now()->addDay()->toDateString())
            ->where('activePending.author', 'Carlos Lima')
            // Sem `audit.view`, o técnico não consulta o histórico de auditoria.
            ->where('can.viewAudit', false));

    $this->actingAs($this->requester)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.status', 'Pendente Terceiros')
            ->where('activePending', null));
});

test('the pending appears in the history and the listing', function () {
    postPending($this->tech, $this->ticket, ['pause_status' => 'awaiting_requester'])->assertSessionHasNoErrors();

    $events = $this->actingAs($this->manager)
        ->getJson("/tickets/{$this->ticket->ticket_number}/audit")
        ->assertOk()
        ->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['title'])->toBe('Pendência registrada')
        ->and($events[0]['changes'])->toBe([['field' => 'Status', 'from' => 'Em atendimento', 'to' => 'Aguardando solicitante']])
        ->and(collect($events[0]['details'])->firstWhere('label', 'Motivo')['value'])->toBe('Aguardando janela de manutenção do servidor.')
        ->and($events[0]['author'])->toBe('Carlos Lima');

    $row = collect($this->actingAs($this->tech)->get('/tickets')->viewData('page')['props']['tickets']['data'])
        ->firstWhere('number', $this->ticket->ticket_number);

    expect($row['status'])->toBe('Aguardando solicitante')
        ->and($row['sla'])->toBe('paused');
});
