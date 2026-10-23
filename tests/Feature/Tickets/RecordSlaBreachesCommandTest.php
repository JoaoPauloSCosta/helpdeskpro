<?php

use App\Enums\SlaState;
use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Todos os chamados abriram às 08:00: primeira resposta até 08:30 e resolução até 16:00.
    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
});

function slaTicketRow(array $overrides = []): Ticket
{
    return Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'sla_first_response_minutes' => 30,
        'sla_resolution_minutes' => 480,
        'first_response_due_at' => '2026-10-21 08:30:00',
        'resolution_due_at' => '2026-10-21 16:00:00',
        ...$overrides,
    ]);
}

function runBreachCommand(string $at): void
{
    test()->travelTo(Carbon::parse($at));
    test()->artisan('sla:record-breaches')->assertSuccessful();
}

function breachIndicators(Ticket $ticket): array
{
    return $ticket->histories()
        ->where('event_type', TicketEvent::SlaBreached)
        ->orderBy('id')
        ->get()
        ->map(fn ($event) => $event->new_values['indicator'])
        ->all();
}

test('the command is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'sla:record-breaches'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('a stopped ticket gets both violations recorded with the detection time', function () {
    $ticket = slaTicketRow();

    runBreachCommand('2026-10-21 17:00:00');

    $ticket = $ticket->fresh();

    expect($ticket->first_response_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and($ticket->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and(breachIndicators($ticket))->toBe(['first_response', 'resolution']);

    $event = $ticket->histories()->where('event_type', TicketEvent::SlaBreached)->first();

    expect($event->user_id)->toBeNull()
        ->and($event->new_values['due_at'])->toBe('2026-10-21T08:30:00+00:00');
});

test('running the command again does not duplicate violations', function () {
    $ticket = slaTicketRow();

    runBreachCommand('2026-10-21 17:00:00');
    runBreachCommand('2026-10-21 17:01:00');
    runBreachCommand('2026-10-22 09:00:00');

    expect(breachIndicators($ticket))->toHaveCount(2)
        ->and($ticket->fresh()->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00');
});

test('only the indicator that is overdue is recorded', function () {
    $ticket = slaTicketRow(['first_response_at' => '2026-10-21 08:10:00']);

    runBreachCommand('2026-10-21 17:00:00');

    expect(breachIndicators($ticket))->toBe(['resolution'])
        ->and($ticket->fresh()->first_response_breached_at)->toBeNull();

    $other = slaTicketRow();
    runBreachCommand('2026-10-21 17:05:00');
    expect(breachIndicators($other))->toBe(['first_response', 'resolution']);
});

test('tickets before their deadlines are left alone', function () {
    $ticket = slaTicketRow();

    runBreachCommand('2026-10-21 08:30:00');

    // No instante do prazo ainda não há violação (só depois dele).
    expect(breachIndicators($ticket))->toBe([]);

    runBreachCommand('2026-10-21 08:31:00');
    expect(breachIndicators($ticket))->toBe(['first_response']);
});

test('paused tickets do not get a resolution violation from the stopped clock', function () {
    // Pausa iniciada às 15:00 (antes do prazo de 16:00): o relógio de resolução está parado.
    $ticket = slaTicketRow(['status' => TicketStatus::Pending, 'first_response_at' => '2026-10-21 08:10:00']);
    $ticket->slaPauses()->forceCreate([
        'pause_status' => TicketStatus::Pending,
        'reason' => 'Aguardando peça',
        'started_at' => '2026-10-21 15:00:00',
        'created_by' => $ticket->requester_id,
    ]);

    runBreachCommand('2026-10-22 12:00:00');

    expect(breachIndicators($ticket))->toBe([])
        ->and($ticket->fresh()->slaState()->value)->toBe('paused');
});

test('a paused ticket still gets the first response violation', function () {
    $ticket = slaTicketRow(['status' => TicketStatus::AwaitingRequester]);

    runBreachCommand('2026-10-21 09:00:00');

    expect(breachIndicators($ticket))->toBe(['first_response']);
});

test('tickets without deadlines are ignored', function () {
    $ticket = slaTicketRow([
        'sla_first_response_minutes' => null,
        'sla_resolution_minutes' => null,
        'first_response_due_at' => null,
        'resolution_due_at' => null,
    ]);

    runBreachCommand('2027-01-01 00:00:00');

    expect(breachIndicators($ticket))->toBe([])
        ->and($ticket->fresh()->first_response_breached_at)->toBeNull();
});

test('resolved and closed tickets are not examined by the command', function () {
    $resolved = slaTicketRow(['status' => TicketStatus::Resolved, 'first_response_at' => '2026-10-21 08:10:00', 'resolved_at' => '2026-10-21 12:00:00']);
    $closed = slaTicketRow(['status' => TicketStatus::Closed, 'first_response_at' => '2026-10-21 08:10:00', 'resolved_at' => '2026-10-21 12:00:00', 'closed_at' => '2026-10-21 13:00:00']);

    runBreachCommand('2026-10-25 12:00:00');

    expect(breachIndicators($resolved))->toBe([])
        ->and(breachIndicators($closed))->toBe([]);
});

test('a first response given on time is not a violation later on', function () {
    $ticket = slaTicketRow(['first_response_at' => '2026-10-21 08:20:00']);

    runBreachCommand('2026-10-21 09:00:00');

    expect(breachIndicators($ticket))->toBe([]);
});

test('the recorded violation stays while the current state follows the new deadline', function () {
    $ticket = slaTicketRow(['first_response_at' => '2026-10-21 08:10:00']);
    runBreachCommand('2026-10-21 17:00:00');

    // Uma meta mais longa depois: o estado atual volta a "No prazo", e a violação histórica continua.
    $ticket->fresh()->forceFill(['resolution_due_at' => '2026-10-22 02:00:00'])->save();
    runBreachCommand('2026-10-21 17:05:00');

    $ticket = $ticket->fresh();

    expect($ticket->slaState(now()))->toBe(SlaState::OnTrack)
        ->and($ticket->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and(breachIndicators($ticket))->toBe(['resolution']);
});

test('many tickets are processed in batches', function () {
    $tickets = collect(range(1, 5))->map(fn () => slaTicketRow());

    runBreachCommand('2026-10-21 17:00:00');

    foreach ($tickets as $ticket) {
        expect(breachIndicators($ticket))->toBe(['first_response', 'resolution']);
    }
});
