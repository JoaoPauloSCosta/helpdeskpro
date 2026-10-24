<?php

use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CloseTicket;
use App\Actions\Tickets\RegisterTicketPending;
use App\Actions\Tickets\ReopenTicket;
use App\Actions\Tickets\ResolveTicket;
use App\Actions\Tickets\UpdateTicketAttributes;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza']);
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Marina Souza']);

    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'assigned_to' => $this->tech->id,
        'requester_id' => $this->requester->id,
        'priority' => TicketPriority::High,
    ]);

    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
});

function slaHistoryUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}/sla-history";
}

function slaHistory(User $user, Ticket $ticket): array
{
    return test()->actingAs($user)->getJson(slaHistoryUrl($ticket))->assertOk()->json();
}

/** Registra a pendência pelo fluxo da CHA-08, a partir do status gravado agora. */
function slaPause(User $editor, Ticket $ticket, TicketStatus $type = TicketStatus::Pending, string $reason = 'Aguardando peça de reposição.', array $details = []): void
{
    $ticket = $ticket->fresh();
    app(RegisterTicketPending::class)($editor, $ticket, $ticket->status, $type, $reason, $details);
}

/** Retoma o atendimento pelo fluxo da CHA-07. */
function slaResume(User $editor, Ticket $ticket): void
{
    $ticket = $ticket->fresh();
    app(ChangeTicketStatus::class)($editor, $ticket, $ticket->status, TicketStatus::InProgress);
}

function slaResolve(User $editor, Ticket $ticket): void
{
    $ticket = $ticket->fresh();
    app(ResolveTicket::class)($editor, $ticket, $ticket->status, 'Peça substituída.');
}

/** Pausa às `$from` e retoma às `$to` do dia de referência. */
function slaPauseCycle(User $editor, Ticket $ticket, string $from, string $to, TicketStatus $type = TicketStatus::Pending): void
{
    test()->travelTo(Carbon::parse("2026-10-21 {$from}"));
    slaPause($editor, $ticket, $type);
    test()->travelTo(Carbon::parse("2026-10-21 {$to}"));
    slaResume($editor, $ticket);
}

/* ---------- Acesso e autorização ---------- */

test('guests are redirected to login', function () {
    $this->get(slaHistoryUrl($this->ticket))->assertRedirect('/login');
});

test('the requester has no access to the SLA history (restricted view)', function () {
    $this->actingAs($this->requester)->getJson(slaHistoryUrl($this->ticket))
        ->assertForbidden()
        ->assertJsonMissingPath('pauses');
});

test('users with access to the ticket can consult its SLA history', function (string $who) {
    $user = match ($who) {
        'tech' => $this->tech,
        'manager' => $this->manager,
        'admin' => User::factory()->withRole('admin')->create(),
    };

    $this->actingAs($user)->getJson(slaHistoryUrl($this->ticket))
        ->assertOk()
        ->assertJsonPath('ticket', $this->ticket->ticket_number)
        ->assertJsonStructure(['ticket', 'pauses' => ['count', 'closed_seconds', 'items'], 'events'])
        ->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'técnico responsável' => 'tech',
    'gestor com visibilidade global' => 'manager',
    'administrador com visibilidade global' => 'admin',
]);

test('users without access to the ticket are forbidden and receive no SLA data', function (string $role) {
    slaPause($this->tech, $this->ticket, reason: 'Motivo confidencial');
    $outsider = User::factory()->withRole($role)->create();

    $this->actingAs($outsider)->getJson(slaHistoryUrl($this->ticket))
        ->assertForbidden()
        ->assertDontSee('Motivo confidencial')
        ->assertJsonMissingPath('pauses');
})->with([
    'outro solicitante' => 'requester',
    'técnico sem atribuição' => 'technician',
]);

test('inactive users are forbidden', function () {
    $this->requester->forceFill(['is_active' => false])->save();

    $this->actingAs($this->requester)->getJson(slaHistoryUrl($this->ticket))->assertForbidden();
});

test('unknown ticket numbers respond as not found', function () {
    $this->actingAs($this->manager)->getJson('/tickets/HD-ZZZZZZZZ/sla-history')->assertNotFound();
});

test('only the records of the requested ticket are returned', function () {
    $other = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'assigned_to' => $this->tech->id,
    ]);
    slaPauseCycle($this->tech, $other, '09:00', '10:00');
    slaPause($this->tech, $this->ticket);

    $history = slaHistory($this->manager, $this->ticket);
    $ownEvents = TicketHistory::where('ticket_id', $this->ticket->id)->pluck('id')->all();

    expect($history['pauses']['count'])->toBe(1)
        ->and($history['pauses']['closed_seconds'])->toBeNull()
        ->and(array_column($history['events'], 'id'))->toEqualCanonicalizing($ownEvents);
});

/* ---------- Pausas e retomadas (RF-15.2, RF-17) ---------- */

test('the four PRD waiting statuses are the ones that pause the resolution SLA', function () {
    expect(TicketStatus::paused())->toEqualCanonicalizing([
        TicketStatus::AwaitingRequester,
        TicketStatus::Pending,
        TicketStatus::PendingThirdParty,
        TicketStatus::PendingDepartments,
    ]);
});

test('each waiting status is shown as a pause and its resume as a resume', function (TicketStatus $type) {
    slaPauseCycle($this->tech, $this->ticket, '09:00', '09:45', $type);

    $history = slaHistory($this->tech, $this->ticket);

    expect($history['pauses']['items'])->toHaveCount(1)
        ->and($history['pauses']['items'][0])->toMatchArray([
            'number' => 1,
            'type' => $type->label(),
            'duration_seconds' => 2700,
        ])
        ->and($history['events'][0])->toMatchArray([
            'kind' => 'resumed',
            'from' => $type->label(),
            'to' => 'Em atendimento',
            'duration_seconds' => 2700,
            'pause_recorded' => true,
        ])
        ->and($history['events'][1])->toMatchArray(['kind' => 'pause_started', 'to' => $type->label()]);
})->with([
    'aguardando solicitante' => TicketStatus::AwaitingRequester,
    'pendente' => TicketStatus::Pending,
    'pendente terceiros' => TicketStatus::PendingThirdParty,
    'pendente departamentos' => TicketStatus::PendingDepartments,
]);

test('the resume records the paused interval, extends the resolution deadline and keeps the first response deadline', function () {
    $deadlines = [
        'first_response_due_at' => Carbon::parse('2026-10-21 10:00:00'),
        'resolution_due_at' => Carbon::parse('2026-10-21 16:00:00'),
    ];
    $this->ticket->forceFill($deadlines)->save();

    slaPauseCycle($this->tech, $this->ticket, '10:00', '12:30');

    $history = slaHistory($this->tech, $this->ticket);
    $ticket = $this->ticket->fresh();

    expect($history['pauses']['items'][0])->toMatchArray([
        'started_at' => '2026-10-21T10:00:00+00:00',
        'ended_at' => '2026-10-21T12:30:00+00:00',
        'duration_seconds' => 9000,
    ])
        ->and($history['events'][0]['duration_seconds'])->toBe(9000)
        ->and($ticket->first_response_due_at->equalTo($deadlines['first_response_due_at']))->toBeTrue()
        // 16:00 + 2h30 parados (RF-17).
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 18:30:00');
});

test('each pause cycle is kept as an individual period', function () {
    slaPauseCycle($this->tech, $this->ticket, '09:00', '09:30', TicketStatus::AwaitingRequester);
    slaPauseCycle($this->tech, $this->ticket, '11:00', '13:00', TicketStatus::PendingThirdParty);

    $history = slaHistory($this->tech, $this->ticket);

    expect($history['pauses']['count'])->toBe(2)
        ->and($history['pauses']['closed_seconds'])->toBe(1800 + 7200)
        ->and(array_column($history['pauses']['items'], 'number'))->toBe([2, 1])
        ->and(array_column($history['pauses']['items'], 'type'))->toBe(['Pendente Terceiros', 'Aguardando solicitante'])
        ->and(array_column($history['pauses']['items'], 'duration_seconds'))->toBe([7200, 1800])
        ->and(array_column($history['events'], 'kind'))->toBe(['resumed', 'pause_started', 'resumed', 'pause_started']);
});

test('a pause in progress has no end nor duration and is left out of the closed total', function () {
    slaPauseCycle($this->tech, $this->ticket, '09:00', '10:00');
    $this->travelTo(Carbon::parse('2026-10-21 11:00:00'));
    slaPause($this->tech, $this->ticket, TicketStatus::AwaitingRequester);
    $this->travelTo(Carbon::parse('2026-10-21 15:00:00'));

    $history = slaHistory($this->tech, $this->ticket);

    expect($history['pauses']['closed_seconds'])->toBe(3600)
        ->and($history['pauses']['items'][0])->toMatchArray([
            'number' => 2,
            'started_at' => '2026-10-21T11:00:00+00:00',
            'ended_at' => null,
            'duration_seconds' => null,
        ]);
});

/* ---------- Reabertura, prioridade e violações ---------- */

test('reopening keeps the first response deadline, the pauses and the late resolution, and extends the resolution deadline', function () {
    // Prazo de 12:00, estendido para 13:00 pela pausa de 1h; resolvido às 14:00, fora do prazo.
    // Reaberto 19h depois: o tempo em Resolvido não conta (D3), então o prazo vai para 08:00 do dia seguinte.
    $this->ticket->forceFill(['resolution_due_at' => Carbon::parse('2026-10-21 12:00:00')])->save();
    slaPauseCycle($this->tech, $this->ticket, '09:00', '10:00');
    $this->travelTo(Carbon::parse('2026-10-21 14:00:00'));
    slaResolve($this->tech, $this->ticket);
    $this->travelTo(Carbon::parse('2026-10-22 09:00:00'));
    $ticket = $this->ticket->fresh();
    app(ReopenTicket::class)($this->requester, $ticket, $ticket->status, 'O problema voltou.');

    $history = slaHistory($this->manager, $this->ticket);

    expect(array_column($history['events'], 'kind'))->toBe(['reopened', 'sla_breached', 'resolved', 'resumed', 'pause_started'])
        ->and($history['events'][0])->toMatchArray([
            'resolution_due_at' => '2026-10-22T08:00:00+00:00',
            'resolution_due_before' => '2026-10-21T13:00:00+00:00',
            'first_response_due_at' => null,
        ])
        ->and($history['events'][1])->toMatchArray([
            'indicator' => 'resolution',
            'due_at' => '2026-10-21T13:00:00+00:00',
        ])
        ->and($history['events'][2])->toMatchArray([
            'resolution_due_at' => '2026-10-21T13:00:00+00:00',
            'on_time' => false,
        ])
        ->and($history['pauses']['items'])->toHaveCount(1)
        ->and($history['pauses']['closed_seconds'])->toBe(3600)
        ->and($this->ticket->fresh()->resolution_due_at->toDateTimeString())->toBe('2026-10-22 08:00:00');
});

test('a priority change keeps the time already paused, the deadlines and earlier violations', function () {
    $this->ticket->forceFill(['resolution_due_at' => Carbon::parse('2026-10-21 12:00:00')])->save();
    // Prazo de 12:00, estendido para 13:30 pela pausa de 1h30; resolvido às 14:00, fora do prazo.
    slaPauseCycle($this->tech, $this->ticket, '09:00', '10:30');
    $this->travelTo(Carbon::parse('2026-10-21 14:00:00'));
    slaResolve($this->tech, $this->ticket);
    $ticket = $this->ticket->fresh();
    app(ReopenTicket::class)($this->requester, $ticket, $ticket->status, 'Ainda ocorre.');
    $this->travelTo(Carbon::parse('2026-10-21 15:00:00'));
    $ticket = $this->ticket->fresh();
    app(UpdateTicketAttributes::class)($this->tech, $ticket, ['priority' => TicketPriority::Critical->value], $ticket->editVersion());

    $history = slaHistory($this->tech, $this->ticket);

    expect($history['events'][0])->toMatchArray(['kind' => 'priority_changed', 'from' => 'Alta', 'to' => 'Crítica'])
        ->and(collect($history['events'])->firstWhere('kind', 'resolved')['on_time'])->toBeFalse()
        ->and($history['pauses']['closed_seconds'])->toBe(5400)
        ->and($this->ticket->fresh()->resolution_due_at->toDateTimeString())->toBe('2026-10-21 13:30:00');
});

test('the resolution outcome comes only from the deadline recorded in the resolution event', function (?string $due, ?bool $onTime) {
    $this->ticket->forceFill(['resolution_due_at' => $due ? Carbon::parse($due) : null])->save();
    $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
    slaResolve($this->tech, $this->ticket);

    // Alterar o prazo do chamado depois não reescreve a apuração gravada no evento.
    $this->ticket->fresh()->forceFill(['resolution_due_at' => Carbon::parse('2030-01-01 00:00:00')])->save();

    // Resolvido após o prazo, a violação é registrada logo depois do evento de resolução.
    $event = collect(slaHistory($this->tech, $this->ticket)['events'])->firstWhere('kind', 'resolved');

    expect($event['kind'])->toBe('resolved')
        ->and($event['on_time'])->toBe($onTime)
        ->and($event['resolution_due_at'])->toBe($due ? Carbon::parse($due)->toIso8601String() : null);
})->with([
    'sem prazo gravado' => [null, null],
    'dentro do prazo' => ['2026-10-21 12:00:00', true],
    'após o prazo' => ['2026-10-21 11:59:59', false],
]);

test('an expired deadline on a ticket in progress is not reported as a recorded event', function () {
    $this->ticket->forceFill(['resolution_due_at' => Carbon::parse('2026-10-20 08:00:00')])->save();

    $history = slaHistory($this->tech, $this->ticket);

    expect($history['events'])->toBe([])
        ->and($history['pauses']['count'])->toBe(0);
});

test('closing is shown and keeps the earlier SLA records', function () {
    slaPauseCycle($this->tech, $this->ticket, '09:00', '09:10');
    slaResolve($this->tech, $this->ticket);
    $ticket = $this->ticket->fresh();
    app(CloseTicket::class)($this->manager, $ticket, $ticket->status);

    $history = slaHistory($this->manager, $this->ticket);

    expect(array_column($history['events'], 'kind'))->toBe(['closed', 'resolved', 'resumed', 'pause_started'])
        ->and($history['pauses']['closed_seconds'])->toBe(600);
});

/* ---------- Dados ausentes ---------- */

test('a ticket without SLA records returns an empty history', function () {
    expect(slaHistory($this->manager, $this->ticket))->toBe([
        'ticket' => $this->ticket->ticket_number,
        'sla' => [
            'priority' => 'Alta',
            'has_policy' => false,
            'first_response_minutes' => null,
            'resolution_minutes' => null,
            'consumed_seconds' => null,
            'remaining_seconds' => null,
            'effective_seconds' => null,
            'breaches' => ['first_response_at' => null, 'resolution_at' => null],
        ],
        'pauses' => ['count' => 0, 'closed_seconds' => null, 'items' => []],
        'events' => [],
    ]);
});

test('a resume without a recorded pause keeps the duration unknown instead of inventing one', function () {
    // Chamado pausado antes do registro de pendências (CHA-08): não há período gravado.
    $legacy = Ticket::factory()->create(['status' => TicketStatus::Pending, 'assigned_to' => $this->tech->id]);
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    slaResume($this->tech, $legacy);

    $history = slaHistory($this->tech, $legacy);

    expect($history['pauses'])->toBe(['count' => 0, 'closed_seconds' => null, 'items' => []])
        ->and($history['events'])->toHaveCount(1)
        ->and($history['events'][0])->toMatchArray([
            'kind' => 'resumed',
            'from' => 'Pendente',
            'duration_seconds' => null,
            'pause_recorded' => false,
        ]);
});

test('status changes and events unrelated to pauses are left out', function () {
    $open = Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_to' => $this->tech->id]);
    slaResume($this->tech, $open); // Aberto → Em atendimento: não envolve pausa.
    $open->histories()->forceCreate(['event_type' => TicketEvent::CategoryChanged, 'created_at' => now()]);

    expect(slaHistory($this->tech, $open)['events'])->toBe([]);
});

/* ---------- Consulta somente leitura ---------- */

test('consulting the history changes no record', function () {
    slaPauseCycle($this->tech, $this->ticket, '09:00', '10:00');
    slaPause($this->tech, $this->ticket);
    $snapshot = fn () => [
        DB::table('tickets')->get()->toArray(),
        DB::table('ticket_sla_pauses')->get()->toArray(),
        DB::table('ticket_histories')->get()->toArray(),
    ];
    $before = $snapshot();
    $this->travelTo(Carbon::parse('2026-10-21 18:00:00'));

    slaHistory($this->tech, $this->ticket);
    slaHistory($this->manager, $this->ticket);

    expect($snapshot())->toEqual($before);
});

/* ---------- Escopo dos dados ---------- */

test('pause reason, pending details and authors are sent only to the support team', function () {
    slaPause($this->tech, $this->ticket, TicketStatus::PendingThirdParty, 'Fornecedor analisando o defeito.', [
        'supplier_name' => 'Acme Peças',
        'protocol_number' => 'PRT-4471',
        'responsible_name' => 'João Pereira',
        'expected_return_at' => '2026-10-25',
    ]);

    // O solicitante nem chega ao histórico de SLA (RF-24): nada da pendência vaza.
    $this->actingAs($this->requester)->getJson(slaHistoryUrl($this->ticket))
        ->assertForbidden()
        ->assertDontSee('Fornecedor analisando o defeito.')
        ->assertDontSee('Acme Peças')
        ->assertDontSee('PRT-4471')
        ->assertDontSee('Carlos Lima')
        ->assertJsonMissingPath('pauses.items.0.reason');

    $support = slaHistory($this->tech, $this->ticket);

    expect($support['pauses']['items'][0])->toMatchArray([
        'reason' => 'Fornecedor analisando o defeito.',
        'supplier_name' => 'Acme Peças',
        'protocol_number' => 'PRT-4471',
        'responsible_name' => 'João Pereira',
        'expected_return_at' => '2026-10-25',
        'author' => 'Carlos Lima',
    ])
        ->and($support['events'][0]['author'])->toBe('Carlos Lima');
});

/* ---------- Ordenação e consultas ---------- */

test('events and pauses are ordered from newest to oldest, ties by recording order', function () {
    // Pausa e retomada no mesmo instante: o desempate é a ordem de gravação.
    slaPauseCycle($this->tech, $this->ticket, '09:00', '09:00');
    slaPauseCycle($this->tech, $this->ticket, '10:00', '10:05');

    $history = slaHistory($this->tech, $this->ticket);
    $ids = array_column($history['events'], 'id');

    expect($ids)->toBe(collect($ids)->sortDesc()->values()->all())
        ->and(array_column($history['events'], 'created_at'))->toBe([
            '2026-10-21T10:05:00+00:00', '2026-10-21T10:00:00+00:00',
            '2026-10-21T09:00:00+00:00', '2026-10-21T09:00:00+00:00',
        ])
        ->and(array_column($history['pauses']['items'], 'duration_seconds'))->toBe([300, 0]);
});

test('the number of queries does not grow with the number of records', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        slaHistory($this->tech, $this->ticket);

        return count(DB::getQueryLog());
    };

    slaPauseCycle($this->tech, $this->ticket, '09:00', '09:30');
    $few = $count();

    slaPauseCycle($this->tech, $this->ticket, '10:00', '10:30', TicketStatus::PendingThirdParty);
    slaPauseCycle($this->tech, $this->ticket, '11:00', '11:30', TicketStatus::AwaitingRequester);
    slaPauseCycle($this->tech, $this->ticket, '12:00', '12:30');
    $many = $count();

    expect($many)->toBe($few);
});
