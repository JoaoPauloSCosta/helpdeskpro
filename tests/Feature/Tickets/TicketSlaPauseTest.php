<?php

use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\RegisterTicketPending;
use App\Enums\SlaState;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Support\SlaClock;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->requester = User::factory()->withRole('requester')->create();
    $this->tech = User::factory()->withRole('technician')->create();

    // Aberto às 08:00, primeira resposta em 30 min (já dada às 08:10) e resolução em 8h (16:00).
    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::High,
        'requester_id' => $this->requester->id,
        'assigned_to' => $this->tech->id,
        'sla_first_response_minutes' => 30,
        'sla_resolution_minutes' => 480,
        'first_response_due_at' => '2026-10-21 08:30:00',
        'first_response_at' => '2026-10-21 08:10:00',
        'resolution_due_at' => '2026-10-21 16:00:00',
    ]);
});

/** Pausa às `$from` e retoma às `$to`, devolvendo o chamado atualizado. */
function pauseAndResume(Ticket $ticket, string $from, string $to, TicketStatus $type = TicketStatus::Pending): Ticket
{
    $tech = test()->tech;

    test()->travelTo(Carbon::parse($from));
    app(RegisterTicketPending::class)($tech, $ticket->fresh(), TicketStatus::InProgress, $type, 'Aguardando retorno.');

    test()->travelTo(Carbon::parse($to));
    app(ChangeTicketStatus::class)($tech, $ticket->fresh(), $type, TicketStatus::InProgress);

    return $ticket->fresh();
}

test('resuming extends the resolution deadline by the paused time', function () {
    $ticket = pauseAndResume($this->ticket, '2026-10-21 10:00:00', '2026-10-21 11:30:00');

    expect($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 17:30:00');
});

test('the resume event records the deadline before and after', function () {
    pauseAndResume($this->ticket, '2026-10-21 10:00:00', '2026-10-21 11:30:00');

    $event = $this->ticket->histories()->where('event_type', TicketEvent::StatusChanged)->sole();

    expect($event->new_values['sla_pause'])->toMatchArray([
        'duration_seconds' => 5400,
        'resolution_due_before' => '2026-10-21T16:00:00+00:00',
        'resolution_due_after' => '2026-10-21T17:30:00+00:00',
    ]);
});

test('several pause and resume cycles add up', function () {
    pauseAndResume($this->ticket, '2026-10-21 09:00:00', '2026-10-21 10:00:00');
    pauseAndResume($this->ticket, '2026-10-21 11:00:00', '2026-10-21 13:00:00', TicketStatus::PendingThirdParty);
    $ticket = pauseAndResume($this->ticket, '2026-10-21 14:00:00', '2026-10-21 14:30:00', TicketStatus::PendingDepartments);

    expect($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 19:30:00')
        ->and($ticket->slaPauses()->sum('duration_seconds'))->toBe(3 * 3600 + 1800);
});

test('the paused time does not count as consumed', function () {
    // Das 08:00 às 14:00 passaram 6h, 2h delas paradas: 4h consumidas.
    $ticket = pauseAndResume($this->ticket, '2026-10-21 09:00:00', '2026-10-21 11:00:00');

    expect(SlaClock::consumedResolutionSeconds($ticket, Carbon::parse('2026-10-21 14:00:00')))->toBe(4 * 3600)
        ->and(SlaClock::remainingResolutionSeconds($ticket, Carbon::parse('2026-10-21 14:00:00')))->toBe(4 * 3600);
});

test('the first response is not affected by pauses', function () {
    $ticket = pauseAndResume($this->ticket, '2026-10-21 09:00:00', '2026-10-21 11:00:00');

    expect($ticket->first_response_due_at->toDateTimeString())->toBe('2026-10-21 08:30:00')
        ->and($ticket->first_response_at->toDateTimeString())->toBe('2026-10-21 08:10:00')
        ->and($ticket->firstResponseSlaState())->toBe(SlaState::Met);
});

test('a paused ticket does not become overdue while it is paused', function () {
    // Pausa às 15:00 (restava 1h). Às 20:00 ainda está pausado, sem violação.
    $this->travelTo(Carbon::parse('2026-10-21 15:00:00'));
    app(RegisterTicketPending::class)($this->tech, $this->ticket->fresh(), TicketStatus::InProgress, TicketStatus::Pending, 'Aguardando retorno.');

    $this->travelTo(Carbon::parse('2026-10-21 20:00:00'));
    $this->actingAs($this->tech)
        ->post("/tickets/{$this->ticket->ticket_number}/comments", ['body' => 'Seguimos aguardando.'])
        ->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    expect($ticket->slaState())->toBe(SlaState::Paused)
        ->and($ticket->resolution_breached_at)->toBeNull()
        ->and($ticket->histories()->where('event_type', TicketEvent::SlaBreached)->count())->toBe(0)
        ->and(SlaClock::remainingResolutionSeconds($ticket, now()))->toBe(3600);
});

test('a deadline already overdue is recorded as a breach before the pause extends it', function () {
    // Venceu às 16:00; a pausa começa às 17:00 e vai até 19:00, estendendo o prazo para 18:00.
    $ticket = pauseAndResume($this->ticket, '2026-10-21 17:00:00', '2026-10-21 19:00:00');

    $breach = $ticket->histories()->where('event_type', TicketEvent::SlaBreached)->sole();

    expect($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 18:00:00')
        ->and($ticket->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and($breach->new_values['indicator'])->toBe('resolution')
        ->and($breach->new_values['due_at'])->toBe('2026-10-21T16:00:00+00:00');
});

test('a ticket without deadlines resumes without errors and without inventing a deadline', function () {
    $this->ticket->forceFill([
        'sla_first_response_minutes' => null,
        'sla_resolution_minutes' => null,
        'first_response_due_at' => null,
        'resolution_due_at' => null,
    ])->save();

    $ticket = pauseAndResume($this->ticket, '2026-10-21 10:00:00', '2026-10-21 11:00:00');

    expect($ticket->resolution_due_at)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::InProgress);
});

test('a ticket paused before the pause records existed keeps its deadline on resume', function () {
    $this->ticket->forceFill(['status' => TicketStatus::Pending])->save();
    $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));

    app(ChangeTicketStatus::class)($this->tech, $this->ticket->fresh(), TicketStatus::Pending, TicketStatus::InProgress);

    $ticket = $this->ticket->fresh();

    expect($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 16:00:00')
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->slaPauses()->count())->toBe(0);
});
