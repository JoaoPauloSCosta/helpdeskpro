<?php

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Marina Souza']);
    $this->requester = User::factory()->withRole('requester')->create();

    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'assigned_to' => $this->tech->id,
        'requester_id' => $this->requester->id,
        'title' => 'Impressora sem toner',
        'priority' => TicketPriority::High,
    ]);
});

const SUMMARY = "Toner substituído e cabeçote limpo.\nImpressão de teste sem falhas.";

function resolutionUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}/resolution";
}

/** Envia a resolução; sem `$expected`, usa o status gravado agora (o que a tela mostraria). */
function postResolution(User $user, Ticket $ticket, array $data = [], ?string $expected = null)
{
    return test()->actingAs($user)->post(resolutionUrl($ticket), [
        'resolution_summary' => SUMMARY,
        'expected_status' => $expected ?? $ticket->fresh()->status->value,
        ...$data,
    ]);
}

/** Nada foi gravado: status, solução e auditoria intactos. */
function expectNotResolved(Ticket $ticket, TicketStatus $status = TicketStatus::InProgress): void
{
    $fresh = $ticket->fresh();

    expect($fresh->status)->toBe($status)
        ->and($fresh->resolution_summary)->toBeNull()
        ->and($fresh->resolved_at)->toBeNull()
        ->and(TicketHistory::count())->toBe(0);
}

/* ---------- Acesso e permissão ---------- */

test('guests cannot resolve a ticket', function () {
    $this->post(resolutionUrl($this->ticket), ['resolution_summary' => SUMMARY, 'expected_status' => 'in_progress'])
        ->assertRedirect('/login');

    expectNotResolved($this->ticket);
});

test('users without tickets.resolve cannot resolve', function (string $role) {
    $user = $role === 'requester' ? $this->requester : User::factory()->withRole($role)->create();

    postResolution($user, $this->ticket)->assertForbidden();

    expectNotResolved($this->ticket);
})->with([
    'solicitante dono do chamado' => 'requester',
    'gestor (altera status e encerra, mas não resolve)' => 'manager',
    'administrador com visibilidade global' => 'admin', // RN-03: ver não é alterar
]);

test('technicians cannot resolve tickets outside their access', function () {
    $other = User::factory()->withRole('technician')->create();

    postResolution($other, $this->ticket)->assertForbidden();

    expectNotResolved($this->ticket);
});

test('inactive users are rejected', function () {
    $this->tech->forceFill(['is_active' => false])->save();

    postResolution($this->tech, $this->ticket)->assertForbidden();

    expectNotResolved($this->ticket);
});

test('an unknown ticket returns not found', function () {
    $this->actingAs($this->tech)
        ->post('/tickets/HD-ZZZZZZZZ/resolution', ['resolution_summary' => SUMMARY, 'expected_status' => 'in_progress'])
        ->assertNotFound();
});

/* ---------- Resolução válida ---------- */

test('the assigned technician resolves a ticket in progress', function () {
    postResolution($this->tech, $this->ticket)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number))
        ->assertSessionHas('success', 'Chamado resolvido. A solução foi registrada.');

    $ticket = $this->ticket->fresh();

    expect($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->resolution_summary)->toBe(SUMMARY)
        ->and($ticket->resolved_at)->not->toBeNull()
        ->and($ticket->closed_at)->toBeNull();
});

test('the summary is stored without surrounding spaces', function () {
    postResolution($this->tech, $this->ticket, ['resolution_summary' => "   Cabo de rede trocado.  \n"])->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->resolution_summary)->toBe('Cabo de rede trocado.');
});

test('a summary at the length limit is accepted', function () {
    $summary = str_repeat('é', Ticket::RESOLUTION_SUMMARY_MAX_LENGTH);

    postResolution($this->tech, $this->ticket, ['resolution_summary' => $summary])->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->resolution_summary)->toBe($summary);
});

/* ---------- Validação ---------- */

test('the summary is required and limited', function (mixed $summary) {
    postResolution($this->tech, $this->ticket, ['resolution_summary' => $summary])->assertSessionHasErrors('resolution_summary');

    expectNotResolved($this->ticket);
})->with([
    'vazio' => '',
    'só espaços' => '   ',
    'só quebras de linha' => "\n\n",
    'nulo' => null,
    'lista' => [['resumo']],
    'longo demais' => fn () => str_repeat('a', Ticket::RESOLUTION_SUMMARY_MAX_LENGTH + 1),
]);

test('a missing summary field is rejected', function () {
    $this->actingAs($this->tech)
        ->post(resolutionUrl($this->ticket), ['expected_status' => 'in_progress'])
        ->assertSessionHasErrors(['resolution_summary' => 'Descreva a solução aplicada.']);

    expectNotResolved($this->ticket);
});

test('the expected status is required and must be a known status', function (mixed $expected) {
    $this->actingAs($this->tech)
        ->post(resolutionUrl($this->ticket), ['resolution_summary' => SUMMARY, 'expected_status' => $expected])
        ->assertSessionHasErrors('expected_status');

    expectNotResolved($this->ticket);
})->with([null, 'waiting']);

/* ---------- Estado do chamado ---------- */

test('only tickets in progress can be resolved', function (TicketStatus $status) {
    $this->ticket->forceFill(['status' => $status])->save();

    // O status esperado é o real: não é conflito, é estado incompatível.
    postResolution($this->tech, $this->ticket)
        ->assertSessionHasErrors(['status' => 'Este chamado não pode ser resolvido no estado atual.']);

    expectNotResolved($this->ticket, $status);
})->with([
    'aberto' => TicketStatus::Open,
    'aguardando solicitante' => TicketStatus::AwaitingRequester,
    'pendente' => TicketStatus::Pending,
    'pendente terceiros' => TicketStatus::PendingThirdParty,
    'pendente departamentos' => TicketStatus::PendingDepartments,
    'encerrado' => TicketStatus::Closed,
]);

test('an already resolved ticket is not resolved again', function () {
    postResolution($this->tech, $this->ticket)->assertSessionHasNoErrors();
    $first = $this->ticket->fresh();

    postResolution($this->tech, $this->ticket, ['resolution_summary' => 'Outra solução'])
        ->assertSessionHasErrors('status');

    $ticket = $this->ticket->fresh();

    expect($ticket->resolution_summary)->toBe(SUMMARY)
        ->and($ticket->resolved_at->equalTo($first->resolved_at))->toBeTrue()
        ->and(TicketHistory::count())->toBe(1);
});

test('the status cannot be set to resolved through the status change flow', function () {
    $this->actingAs($this->tech)
        ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'resolved', 'expected_status' => 'in_progress'])
        ->assertSessionHasErrors('status');

    expectNotResolved($this->ticket);
});

test('an open pause blocks the resolution even if the status is inconsistent', function () {
    $this->ticket->slaPauses()->forceCreate([
        'pause_status' => TicketStatus::Pending,
        'reason' => 'Pausa antiga não encerrada',
        'started_at' => now()->subHour(),
        'created_by' => $this->tech->id,
    ]);

    postResolution($this->tech, $this->ticket)->assertSessionHasErrors('status');

    expectNotResolved($this->ticket);
});

/* ---------- Auditoria e integridade ---------- */

test('the history records the resolution with author, summary and server time', function () {
    $this->freezeSecond();
    $due = now()->addHours(2);
    $this->ticket->forceFill(['resolution_due_at' => $due])->save();

    postResolution($this->tech, $this->ticket)->assertSessionHasNoErrors();

    $event = TicketHistory::sole();

    expect($event->event_type)->toBe(TicketEvent::Resolved)
        ->and($event->ticket_id)->toBe($this->ticket->id)
        ->and($event->user_id)->toBe($this->tech->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue()
        ->and($event->old_values)->toBe(['status' => 'in_progress', 'label' => 'Em atendimento'])
        ->and($event->new_values)->toBe([
            'status' => 'resolved',
            'label' => 'Resolvido',
            'resolution_summary' => SUMMARY,
            'resolved_at' => now()->toIso8601String(),
            'resolution_due_at' => $due->toIso8601String(),
        ]);
});

test('author, time and final status always come from the server', function () {
    $this->freezeSecond();

    postResolution($this->tech, $this->ticket, [
        'user_id' => $this->manager->id,
        'resolved_by' => $this->manager->id,
        'resolved_at' => '2020-01-01 00:00:00',
        'created_at' => '2020-01-01 00:00:00',
        'status' => 'closed',
        'closed_at' => '2020-01-01 00:00:00',
    ])->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    expect($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->resolved_at->equalTo(now()))->toBeTrue()
        ->and($ticket->closed_at)->toBeNull()
        ->and($ticket->updated_at->equalTo(now()))->toBeTrue()
        ->and(TicketHistory::sole()->user_id)->toBe($this->tech->id)
        ->and(TicketHistory::sole()->created_at->equalTo(now()))->toBeTrue();
});

test('resolving does not touch any unrelated field of the ticket', function () {
    $other = User::factory()->withRole('technician')->create();
    $before = $this->ticket->fresh()->getAttributes();

    postResolution($this->tech, $this->ticket, [
        'assigned_to' => $other->id,
        'requester_id' => $other->id,
        'department_id' => 999,
        'category_id' => 999,
        'priority' => 'low',
        'title' => 'Título manipulado',
        'ticket_number' => 'HD-AAAAAAAA',
        'resolution_due_at' => now()->addYear()->toDateTimeString(),
        'first_response_at' => now()->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $changed = ['status', 'resolution_summary', 'resolved_at', 'updated_at'];
    $after = $this->ticket->fresh()->getAttributes();

    expect(Arr::except($after, $changed))->toEqual(Arr::except($before, $changed))
        ->and($after['status'])->toBe('resolved');
});

test('a status changed by someone else is reported as a conflict and not overwritten', function () {
    // A tela foi aberta com "Em atendimento"; outra pessoa registrou uma pendência nesse meio-tempo.
    $this->ticket->forceFill(['status' => TicketStatus::PendingThirdParty])->save();

    postResolution($this->tech, $this->ticket, expected: 'in_progress')
        ->assertSessionHasErrors('conflict')
        ->assertSessionMissing('success');

    expectNotResolved($this->ticket, TicketStatus::PendingThirdParty);
});

test('a repeated or concurrent submission resolves the ticket only once', function () {
    postResolution($this->tech, $this->ticket, expected: 'in_progress')->assertSessionHasNoErrors();
    postResolution($this->tech, $this->ticket, ['resolution_summary' => 'Segunda tentativa'], expected: 'in_progress')
        ->assertSessionHasErrors('conflict');

    $ticket = $this->ticket->fresh();

    expect($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->resolution_summary)->toBe(SUMMARY)
        ->and(TicketHistory::count())->toBe(1);
});

test('a failure while recording the audit leaves nothing behind', function () {
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));
    $this->withoutExceptionHandling();

    expect(fn () => postResolution($this->tech, $this->ticket))->toThrow(RuntimeException::class, 'falha simulada na auditoria');

    expectNotResolved($this->ticket);
});

test('a failure while saving the ticket rolls back the audit event', function () {
    Ticket::saving(fn () => throw new RuntimeException('falha simulada no chamado'));
    $this->withoutExceptionHandling();

    expect(fn () => postResolution($this->tech, $this->ticket))->toThrow(RuntimeException::class, 'falha simulada no chamado');

    expectNotResolved($this->ticket);
});

test('a new resolution after a reopening preserves the previous one in the history', function () {
    $this->freezeSecond();
    postResolution($this->tech, $this->ticket)->assertSessionHasNoErrors();
    $firstAt = now()->copy();

    // Simula o chamado de volta ao atendimento; o ciclo completo com a reabertura está no TicketReopeningTest.
    $this->ticket->fresh()->forceFill(['status' => TicketStatus::InProgress])->save();
    $this->travel(2)->hours();

    postResolution($this->tech, $this->ticket, ['resolution_summary' => 'Fusor substituído.'])->assertSessionHasNoErrors();

    $events = TicketHistory::where('event_type', TicketEvent::Resolved)->orderBy('id')->get();
    $ticket = $this->ticket->fresh();

    expect($events)->toHaveCount(2)
        ->and($events[0]->new_values['resolution_summary'])->toBe(SUMMARY)
        ->and($events[1]->old_values)->toBe([
            'status' => 'in_progress',
            'label' => 'Em atendimento',
            'resolution_summary' => SUMMARY,
            'resolved_at' => $firstAt->toIso8601String(),
        ])
        ->and($events[1]->new_values['resolution_summary'])->toBe('Fusor substituído.')
        ->and($ticket->resolution_summary)->toBe('Fusor substituído.')
        ->and($ticket->resolved_at->equalTo(now()))->toBeTrue();
});

/* ---------- SLA ---------- */

test('resolving keeps deadlines and first response and compares the stored instants', function (int $dueInMinutes, string $state) {
    $this->freezeSecond();
    $recorded = [
        'first_response_due_at' => now()->subHours(3),
        'first_response_at' => now()->subHours(4),
        'resolution_due_at' => now()->addMinutes($dueInMinutes),
    ];
    $this->ticket->forceFill($recorded)->save();

    postResolution($this->tech, $this->ticket)->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    // Resolver não recalcula prazo nem primeira resposta; só `resolved_at` e o tempo efetivo são gravados.
    foreach ($recorded as $column => $value) {
        expect($ticket->{$column}->equalTo($value))->toBeTrue();
    }
    expect($ticket->slaState()->value)->toBe($state)
        ->and($ticket->firstResponseSlaState()->value)->toBe('met');
})->with([
    'dentro do prazo' => [30, 'met'],
    'no limite do prazo' => [0, 'met'],
    'prazo vencido' => [-30, 'overdue'],
]);

test('without a recorded deadline the SLA is not declared met', function () {
    postResolution($this->tech, $this->ticket)->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    expect($ticket->resolution_due_at)->toBeNull()
        ->and($ticket->slaState()->value)->toBe('none')
        ->and(TicketHistory::sole()->new_values['resolution_due_at'])->toBeNull();
});

test('resolving does not count as first response', function () {
    $this->ticket->forceFill(['first_response_due_at' => now()->addHour()])->save();

    postResolution($this->tech, $this->ticket)->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->first_response_at)->toBeNull();
});

/* ---------- Encerramento (CHA-11) continua separado ---------- */

test('resolving does not close the ticket and no flow closes it yet', function () {
    postResolution($this->tech, $this->ticket)->assertSessionHasNoErrors();

    // O gestor tem `tickets.close`, mas o encerramento é o CHA-11, não a alteração de status.
    $this->actingAs($this->manager)
        ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'closed', 'expected_status' => 'resolved'])
        ->assertSessionHasErrors('status');

    $ticket = $this->ticket->fresh();

    expect($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->closed_at)->toBeNull();
});

/* ---------- Tela de detalhes ---------- */

test('users without tickets.resolve receive neither the action nor the form', function (string $role) {
    $user = $role === 'requester' ? $this->requester : User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.resolve', false)
            ->where('resolutionForm', null));
})->with(['requester', 'manager', 'admin']);

test('the assigned technician is offered the resolution on a ticket in progress', function () {
    $this->actingAs($this->tech)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.resolve', true)
            ->where('resolutionForm', [
                'current' => 'in_progress',
                'limits' => ['summary' => Ticket::RESOLUTION_SUMMARY_MAX_LENGTH],
            ])
            ->where('resolution', null));
});

test('the resolution is not offered outside of in progress', function (TicketStatus $status) {
    $this->ticket->forceFill(['status' => $status])->save();

    $this->actingAs($this->tech)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.resolve', false)
            // Mantido para o modal mostrar o status que venceu após um conflito.
            ->where('resolutionForm.current', $status->value));
})->with([TicketStatus::Open, TicketStatus::Pending, TicketStatus::Resolved, TicketStatus::Closed]);

test('the recorded solution is shown to everyone with access to the ticket', function () {
    $this->freezeSecond();
    postResolution($this->tech, $this->ticket)->assertSessionHasNoErrors();

    foreach ([$this->requester, $this->tech, $this->manager] as $user) {
        $this->actingAs($user)
            ->get("/tickets/{$this->ticket->ticket_number}")
            ->assertInertia(function (Assert $page) use ($user) {
                $page->where('ticket.status', 'Resolvido')
                    ->where('resolution', [
                        'summary' => SUMMARY,
                        'resolved_at' => now()->toIso8601String(),
                        'author' => 'Carlos Lima',
                    ]);

                // O solicitante não recebe SLA (RF-24).
                $user->is($this->requester)
                    ? $page->where('sla', null)
                    : $page->where('sla.resolution.met_at', now()->toIso8601String());
            });
    }

    $outsider = User::factory()->withRole('requester')->create();
    $this->actingAs($outsider)->get("/tickets/{$this->ticket->ticket_number}")->assertForbidden();
});

test('the resolution appears in the history and the listing', function () {
    postResolution($this->tech, $this->ticket)->assertSessionHasNoErrors();

    $events = $this->actingAs($this->manager)
        ->getJson("/tickets/{$this->ticket->ticket_number}/audit")
        ->assertOk()
        ->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['title'])->toBe('Chamado resolvido')
        ->and($events[0]['changes'])->toBe([['field' => 'Status', 'from' => 'Em atendimento', 'to' => 'Resolvido']])
        ->and(collect($events[0]['details'])->firstWhere('label', 'Solução registrada')['value'])->toBe(SUMMARY)
        ->and($events[0]['author'])->toBe('Carlos Lima');

    $row = collect($this->actingAs($this->tech)->get('/tickets')->viewData('page')['props']['tickets']['data'])
        ->firstWhere('number', $this->ticket->ticket_number);

    expect($row['status'])->toBe('Resolvido');
});
