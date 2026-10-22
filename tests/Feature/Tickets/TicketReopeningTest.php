<?php

use App\Actions\Tickets\ReopenTicket;
use App\Actions\Tickets\ResolveTicket;
use App\Actions\Tickets\TicketStatusConflict;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

const REOPEN_SOLUTION = 'Toner substituído e cabeçote limpo.';
const REOPEN_JUSTIFICATION = "A impressora voltou a falhar hoje cedo.\nAs folhas saem manchadas de novo.";

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Marina Souza']);
    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza']);

    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'assigned_to' => $this->tech->id,
        'requester_id' => $this->requester->id,
        'title' => 'Impressora sem toner',
        'priority' => TicketPriority::High,
    ]);

    // Resolvido pelo fluxo real (CHA-09), com o evento de auditoria correspondente.
    app(ResolveTicket::class)($this->tech, $this->ticket, TicketStatus::InProgress, REOPEN_SOLUTION);
    $this->resolvedAt = $this->ticket->fresh()->resolved_at;
});

function reopeningUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}/reopening";
}

/** Envia a reabertura; sem `$expected`, usa o status gravado agora (o que a tela mostraria). */
function postReopening(User $user, Ticket $ticket, array $data = [], ?string $expected = null)
{
    return test()->actingAs($user)->post(reopeningUrl($ticket), [
        'justification' => REOPEN_JUSTIFICATION,
        'expected_status' => $expected ?? $ticket->fresh()->status->value,
        ...$data,
    ]);
}

function reopenedEvents()
{
    return TicketHistory::where('event_type', TicketEvent::Reopened)->orderBy('id')->get();
}

/** Nada foi gravado: status, solução e auditoria intactos. */
function expectNotReopened(Ticket $ticket, TicketStatus $status = TicketStatus::Resolved): void
{
    $fresh = $ticket->fresh();

    expect($fresh->status)->toBe($status)
        ->and($fresh->resolution_summary)->toBe(REOPEN_SOLUTION)
        ->and(reopenedEvents())->toHaveCount(0)
        // Somente o evento da resolução feita no preparo do teste.
        ->and(TicketHistory::count())->toBe(1);
}

/* ---------- Acesso e propriedade ---------- */

test('guests cannot reopen a ticket', function () {
    $this->post(reopeningUrl($this->ticket), ['justification' => REOPEN_JUSTIFICATION, 'expected_status' => 'resolved'])
        ->assertRedirect('/login');

    expectNotReopened($this->ticket);
});

test('support roles cannot use the requester flow just because of their role', function (string $role) {
    $user = match ($role) {
        'technician' => $this->tech, // responsável pelo chamado
        'manager' => $this->manager, // visibilidade global e `tickets.close`
        default => User::factory()->withRole($role)->create(),
    };

    postReopening($user, $this->ticket)->assertForbidden();

    expectNotReopened($this->ticket);
})->with([
    'técnico responsável' => 'technician',
    'gestor' => 'manager',
    'administrador' => 'admin',
]);

test('another requester cannot reopen the ticket', function () {
    $other = User::factory()->withRole('requester')->create(['department_id' => $this->requester->department_id]);

    postReopening($other, $this->ticket)->assertForbidden();

    expectNotReopened($this->ticket);
});

test('an inactive original requester is rejected', function () {
    $this->requester->forceFill(['is_active' => false])->save();

    postReopening($this->requester, $this->ticket)->assertForbidden();

    expectNotReopened($this->ticket);
});

test('an unknown ticket returns not found', function () {
    $this->actingAs($this->requester)
        ->post('/tickets/HD-ZZZZZZZZ/reopening', ['justification' => REOPEN_JUSTIFICATION, 'expected_status' => 'resolved'])
        ->assertNotFound();
});

test('a support user who is the original requester of their own ticket can reopen it', function () {
    // A regra é a propriedade, não o papel: o técnico também é solicitante dos próprios chamados.
    $ticket = Ticket::factory()->create(['requester_id' => $this->tech->id, 'status' => TicketStatus::Resolved]);

    postReopening($this->tech, $ticket)->assertSessionHasNoErrors();

    expect($ticket->fresh()->status)->toBe(TicketStatus::Open);
});

/* ---------- Reabertura válida ---------- */

test('the original requester reopens a resolved ticket', function () {
    postReopening($this->requester, $this->ticket)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number))
        ->assertSessionHas('success', 'Chamado reaberto. A justificativa foi registrada e o status voltou para Aberto.');

    $ticket = $this->ticket->fresh();

    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->ticket_number)->toBe($this->ticket->ticket_number)
        ->and($ticket->closed_at)->toBeNull()
        ->and(Ticket::count())->toBe(1); // RF-13, regra 7: nenhum chamado novo
});

test('the justification is stored in the reopening event without surrounding spaces', function () {
    postReopening($this->requester, $this->ticket, ['justification' => "   Voltou a falhar.  \n"])->assertSessionHasNoErrors();

    expect(reopenedEvents()->sole()->new_values['justification'])->toBe('Voltou a falhar.');
});

test('a justification at the length limit is accepted', function () {
    $justification = str_repeat('é', Ticket::REOPEN_JUSTIFICATION_MAX_LENGTH);

    postReopening($this->requester, $this->ticket, ['justification' => $justification])->assertSessionHasNoErrors();

    expect(reopenedEvents()->sole()->new_values['justification'])->toBe($justification);
});

/* ---------- Validação ---------- */

test('the justification is required and limited', function (mixed $justification) {
    postReopening($this->requester, $this->ticket, ['justification' => $justification])->assertSessionHasErrors('justification');

    expectNotReopened($this->ticket);
})->with([
    'vazia' => '',
    'só espaços' => '   ',
    'só quebras de linha' => "\n\n",
    'nula' => null,
    'lista' => [['motivo']],
    'longa demais' => fn () => str_repeat('a', Ticket::REOPEN_JUSTIFICATION_MAX_LENGTH + 1),
]);

test('a missing justification field is rejected', function () {
    $this->actingAs($this->requester)
        ->post(reopeningUrl($this->ticket), ['expected_status' => 'resolved'])
        ->assertSessionHasErrors(['justification' => 'Explique o motivo da reabertura.']);

    expectNotReopened($this->ticket);
});

test('the expected status is required and must be a known status', function (mixed $expected) {
    $this->actingAs($this->requester)
        ->post(reopeningUrl($this->ticket), ['justification' => REOPEN_JUSTIFICATION, 'expected_status' => $expected])
        ->assertSessionHasErrors('expected_status');

    expectNotReopened($this->ticket);
})->with([null, 'reopened']);

/* ---------- Estado do chamado ---------- */

test('only resolved tickets can be reopened', function (TicketStatus $status) {
    $this->ticket->forceFill(['status' => $status])->save();

    // O status esperado é o real: não é conflito, é estado incompatível.
    postReopening($this->requester, $this->ticket)
        ->assertSessionHasErrors(['status' => 'Este chamado não pode ser reaberto no estado atual.']);

    expectNotReopened($this->ticket, $status);
})->with([
    'aberto' => TicketStatus::Open,
    'em atendimento' => TicketStatus::InProgress,
    'aguardando solicitante' => TicketStatus::AwaitingRequester,
    'pendente' => TicketStatus::Pending,
    'pendente terceiros' => TicketStatus::PendingThirdParty,
    'pendente departamentos' => TicketStatus::PendingDepartments,
    'encerrado' => TicketStatus::Closed, // RF-13, regra 5
]);

test('a ticket closed while the modal was open is reported as a conflict and not reopened', function () {
    // A tela foi aberta com "Resolvido"; o gestor encerrou o chamado nesse meio-tempo.
    $this->ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now()])->save();

    postReopening($this->requester, $this->ticket, expected: 'resolved')
        ->assertSessionHasErrors('conflict')
        ->assertSessionMissing('success');

    expectNotReopened($this->ticket, TicketStatus::Closed);
    expect($this->ticket->fresh()->closed_at)->not->toBeNull();
});

test('the status cannot be set back to open through the status change flow', function () {
    $this->actingAs($this->manager)
        ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'open', 'expected_status' => 'resolved'])
        ->assertSessionHasErrors('status');

    expectNotReopened($this->ticket);
});

/* ---------- Auditoria e integridade ---------- */

test('the history records resolved to open with author, justification and server time', function () {
    $this->freezeSecond();
    $due = now()->addHours(2);
    $firstDue = now()->subHour();
    $this->ticket->forceFill(['resolution_due_at' => $due, 'first_response_due_at' => $firstDue])->save();

    postReopening($this->requester, $this->ticket)->assertSessionHasNoErrors();

    $event = reopenedEvents()->sole();

    expect($event->ticket_id)->toBe($this->ticket->id)
        ->and($event->user_id)->toBe($this->requester->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue()
        ->and($event->old_values)->toBe([
            'status' => 'resolved',
            'label' => 'Resolvido',
            'resolution_summary' => REOPEN_SOLUTION,
            'resolved_at' => $this->resolvedAt->toIso8601String(),
        ])
        ->and($event->new_values)->toBe([
            'status' => 'open',
            'label' => 'Aberto',
            'justification' => REOPEN_JUSTIFICATION,
            'reopened_at' => now()->toIso8601String(),
            'first_response_due_at' => $firstDue->toIso8601String(),
            'resolution_due_at' => $due->toIso8601String(),
        ]);
});

test('author, time and new status always come from the server', function () {
    $this->freezeSecond();

    postReopening($this->requester, $this->ticket, [
        'user_id' => $this->manager->id,
        'requester_id' => $this->manager->id,
        'reopened_at' => '2020-01-01 00:00:00',
        'created_at' => '2020-01-01 00:00:00',
        'status' => 'in_progress',
    ])->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();
    $event = reopenedEvents()->sole();

    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->requester_id)->toBe($this->requester->id)
        ->and($ticket->updated_at->equalTo(now()))->toBeTrue()
        ->and($event->user_id)->toBe($this->requester->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue()
        ->and($event->new_values['reopened_at'])->toBe(now()->toIso8601String());
});

test('reopening only changes the status of the ticket', function () {
    $other = User::factory()->withRole('technician')->create();
    $before = $this->ticket->fresh()->getAttributes();

    postReopening($this->requester, $this->ticket, [
        'assigned_to' => $other->id,
        'requester_id' => $other->id,
        'department_id' => 999,
        'category_id' => 999,
        'priority' => 'low',
        'title' => 'Título manipulado',
        'ticket_number' => 'HD-AAAAAAAA',
        'resolution_summary' => 'Solução apagada',
        'resolved_at' => null,
        'resolution_due_at' => now()->addYear()->toDateTimeString(),
        'first_response_at' => now()->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $changed = ['status', 'updated_at'];
    $after = $this->ticket->fresh()->getAttributes();

    expect(Arr::except($after, $changed))->toEqual(Arr::except($before, $changed))
        ->and($after['status'])->toBe('open');
});

test('a repeated or concurrent submission reopens the ticket only once', function () {
    postReopening($this->requester, $this->ticket, expected: 'resolved')->assertSessionHasNoErrors();
    postReopening($this->requester, $this->ticket, ['justification' => 'Segunda tentativa'], expected: 'resolved')
        ->assertSessionHasErrors('conflict');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and(reopenedEvents())->toHaveCount(1)
        ->and(reopenedEvents()->sole()->new_values['justification'])->toBe(REOPEN_JUSTIFICATION);
});

test('the action re-checks the locked row when the request was validated against a stale state', function () {
    // Simula a segunda de duas requisições simultâneas: a primeira já gravou a reabertura.
    $stale = $this->ticket->fresh();
    app(ReopenTicket::class)($this->requester, $this->ticket->fresh(), TicketStatus::Resolved, 'Primeira');

    expect(fn () => app(ReopenTicket::class)($this->requester, $stale, TicketStatus::Resolved, 'Segunda'))
        ->toThrow(TicketStatusConflict::class);

    expect(reopenedEvents())->toHaveCount(1)
        ->and(reopenedEvents()->sole()->new_values['justification'])->toBe('Primeira');
});

test('a failure while recording the audit leaves nothing behind', function () {
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));
    $this->withoutExceptionHandling();

    expect(fn () => postReopening($this->requester, $this->ticket))->toThrow(RuntimeException::class, 'falha simulada na auditoria');

    expectNotReopened($this->ticket);
});

test('a failure while saving the ticket rolls back the audit event', function () {
    Ticket::saving(fn () => throw new RuntimeException('falha simulada no chamado'));
    $this->withoutExceptionHandling();

    expect(fn () => postReopening($this->requester, $this->ticket))->toThrow(RuntimeException::class, 'falha simulada no chamado');

    expectNotReopened($this->ticket);
});

/* ---------- Histórico de resolução preservado ---------- */

test('the original resolution and previous reopenings are preserved across cycles', function () {
    $this->freezeSecond();
    $firstResolvedAt = $this->resolvedAt->copy();

    postReopening($this->requester, $this->ticket)->assertSessionHasNoErrors();

    // Segundo ciclo pelo fluxo real: início do atendimento (CHA-07) e nova resolução (CHA-09).
    $this->travel(1)->hours();
    $this->actingAs($this->tech)
        ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'in_progress', 'expected_status' => 'open'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->tech)
        ->post("/tickets/{$this->ticket->ticket_number}/resolution", ['resolution_summary' => 'Fusor substituído.', 'expected_status' => 'in_progress'])
        ->assertSessionHasNoErrors();
    $secondResolvedAt = now()->copy();

    $this->travel(1)->hours();
    postReopening($this->requester, $this->ticket, ['justification' => 'Falhou mais uma vez.'])->assertSessionHasNoErrors();

    $reopenings = reopenedEvents();
    $resolutions = TicketHistory::where('event_type', TicketEvent::Resolved)->orderBy('id')->get();

    expect($reopenings)->toHaveCount(2)
        ->and($reopenings[0]->new_values['justification'])->toBe(REOPEN_JUSTIFICATION)
        ->and($reopenings[0]->old_values['resolution_summary'])->toBe(REOPEN_SOLUTION)
        ->and($reopenings[1]->new_values['justification'])->toBe('Falhou mais uma vez.')
        ->and($reopenings[1]->old_values['resolution_summary'])->toBe('Fusor substituído.')
        ->and($reopenings[1]->old_values['resolved_at'])->toBe($secondResolvedAt->toIso8601String())
        // A primeira resolução continua no evento original e no "anterior" da segunda.
        ->and($resolutions)->toHaveCount(2)
        ->and($resolutions[0]->new_values['resolution_summary'])->toBe(REOPEN_SOLUTION)
        ->and($resolutions[1]->old_values['resolution_summary'])->toBe(REOPEN_SOLUTION)
        ->and($resolutions[1]->old_values['resolved_at'])->toBe($firstResolvedAt->toIso8601String())
        ->and(TicketHistory::count())->toBe(5);
});

/* ---------- SLA ---------- */

test('reopening keeps deadlines, first response, pauses and the previous result', function () {
    $this->freezeSecond();
    $recorded = [
        'first_response_due_at' => now()->subHours(5),
        'first_response_at' => now()->subHours(4), // violada: respondida depois do prazo
        'resolution_due_at' => now()->subHour(), // resolução registrada depois do prazo
    ];
    $this->ticket->forceFill($recorded)->save();
    $pause = $this->ticket->slaPauses()->forceCreate([
        'pause_status' => TicketStatus::PendingThirdParty,
        'reason' => 'Aguardando fornecedor',
        'started_at' => now()->subHours(3),
        'ended_at' => now()->subHours(2),
        'duration_seconds' => 3600,
        'created_by' => $this->tech->id,
    ]);
    $pauseBefore = $pause->fresh()->getAttributes();

    expect($this->ticket->fresh()->slaState()->value)->toBe('overdue');

    postReopening($this->requester, $this->ticket)->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    // Sem motor de SLA (Etapa 7), nada é reiniciado nem recalculado.
    foreach ($recorded as $column => $value) {
        expect($ticket->{$column}->equalTo($value))->toBeTrue();
    }
    expect($ticket->resolved_at->equalTo($this->resolvedAt))->toBeTrue()
        ->and($pause->fresh()->getAttributes())->toBe($pauseBefore)
        ->and($ticket->slaPauses()->count())->toBe(1)
        ->and($ticket->slaPauses()->active()->exists())->toBeFalse()
        ->and($ticket->firstResponseSlaState()->value)->toBe('overdue')
        // Aberto de novo, com o prazo original já vencido: continua fora do SLA.
        ->and($ticket->slaState()->value)->toBe('overdue');
});

test('the detail page does not present a previous resolution as met after reopening', function () {
    $this->freezeSecond();
    $this->ticket->forceFill(['resolution_due_at' => now()->addHours(2)])->save();

    postReopening($this->requester, $this->ticket)->assertSessionHasNoErrors();

    $this->actingAs($this->requester)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.status', 'Aberto')
            ->where('sla.resolution.state', 'on_track')
            ->where('sla.resolution.met_at', null)
            ->where('sla.resolution.due_at', now()->addHours(2)->toIso8601String())
            ->where('resolution', null));
});

/* ---------- Tela de detalhes ---------- */

test('only the original requester is offered the reopening of a resolved ticket', function () {
    $this->actingAs($this->requester)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.reopen', true)
            ->where('reopenForm', [
                'current' => 'resolved',
                'limits' => ['justification' => Ticket::REOPEN_JUSTIFICATION_MAX_LENGTH],
            ])
            ->where('reopening', null));

    foreach ([$this->tech, $this->manager, User::factory()->withRole('admin')->create()] as $user) {
        $this->actingAs($user)
            ->get("/tickets/{$this->ticket->ticket_number}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.reopen', false)
                ->where('reopenForm', null));
    }
});

test('the reopening is not offered outside of resolved', function (TicketStatus $status) {
    $this->ticket->forceFill(['status' => $status])->save();

    $this->actingAs($this->requester)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.reopen', false)
            // Mantido para o modal mostrar o status que venceu após um conflito.
            ->where('reopenForm.current', $status->value));
})->with([TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Pending, TicketStatus::Closed]);

test('the justification is shown on the ticket and in the history after reopening', function () {
    $this->freezeSecond();
    postReopening($this->requester, $this->ticket)->assertSessionHasNoErrors();

    foreach ([$this->requester, $this->tech, $this->manager] as $user) {
        $this->actingAs($user)
            ->get("/tickets/{$this->ticket->ticket_number}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.status', 'Aberto')
                ->where('reopening', [
                    'justification' => REOPEN_JUSTIFICATION,
                    'reopened_at' => now()->toIso8601String(),
                    'author' => 'Ana Souza',
                ]));
    }

    // Auditoria (`audit.view`): o gestor vê a resolução original e a reabertura.
    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('history', 2)
            ->where('history.0.title', 'Chamado resolvido')
            ->where('history.0.summary', REOPEN_SOLUTION)
            ->where('history.1.title', 'Chamado reaberto')
            ->where('history.1.from', 'Resolvido')
            ->where('history.1.to', 'Aberto')
            ->where('history.1.justification', REOPEN_JUSTIFICATION)
            ->where('history.1.author', 'Ana Souza'));

    $row = collect($this->actingAs($this->requester)->get('/tickets')->viewData('page')['props']['tickets']['data'])
        ->firstWhere('number', $this->ticket->ticket_number);

    expect($row['status'])->toBe('Aberto');
});

test('the reopening card disappears once the ticket is resolved again', function () {
    postReopening($this->requester, $this->ticket)->assertSessionHasNoErrors();
    $this->ticket->fresh()->forceFill(['status' => TicketStatus::InProgress])->save();
    app(ResolveTicket::class)($this->tech, $this->ticket->fresh(), TicketStatus::InProgress, 'Fusor substituído.');

    $this->actingAs($this->requester)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('reopening', null)
            ->where('resolution.summary', 'Fusor substituído.')
            ->where('can.reopen', true));
});

/* ---------- Resolução (CHA-09) e encerramento (CHA-11) continuam separados ---------- */

test('a reopened ticket follows the normal cycle and is not resolved or closed by the reopening', function () {
    postReopening($this->requester, $this->ticket)->assertSessionHasNoErrors();

    // Aberto não é resolvido diretamente: precisa voltar ao atendimento (RF-10).
    $this->actingAs($this->tech)
        ->post("/tickets/{$this->ticket->ticket_number}/resolution", ['resolution_summary' => 'x', 'expected_status' => 'open'])
        ->assertSessionHasErrors('status');

    // O solicitante não encerra chamados (RF-14) nem resolve.
    $this->actingAs($this->requester)
        ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'closed', 'expected_status' => 'open'])
        ->assertForbidden();

    $ticket = $this->ticket->fresh();

    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->closed_at)->toBeNull()
        ->and(TicketHistory::count())->toBe(2);
});
