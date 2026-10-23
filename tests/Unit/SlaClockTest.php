<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketSlaPause;
use App\Support\SlaClock;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Sobe o framework para os modelos Eloquent; nenhum teste aqui usa o banco.
uses(TestCase::class);

/**
 * Chamado montado em memória (sem banco). `activeSlaPause` é informado à mão para evitar a consulta.
 */
function slaTicket(array $attributes = [], ?Carbon $pauseStartedAt = null): Ticket
{
    $ticket = new Ticket;
    $ticket->forceFill([
        'status' => TicketStatus::InProgress,
        'sla_resolution_minutes' => 480,
        'resolution_due_at' => Carbon::parse('2026-10-21 16:00:00'),
        ...$attributes,
    ]);

    $pause = null;

    if ($pauseStartedAt) {
        $pause = new TicketSlaPause;
        $pause->forceFill(['started_at' => $pauseStartedAt]);
    }

    return $ticket->setRelation('activeSlaPause', $pause);
}

test('a ticket without policy has no consumed or remaining time', function () {
    $ticket = slaTicket(['sla_resolution_minutes' => null, 'resolution_due_at' => null]);
    $now = Carbon::parse('2026-10-21 12:00:00');

    expect(SlaClock::targetSeconds($ticket))->toBeNull()
        ->and(SlaClock::consumedResolutionSeconds($ticket, $now))->toBeNull()
        ->and(SlaClock::remainingResolutionSeconds($ticket, $now))->toBeNull();
});

test('without pauses the consumed time is the time since the start', function () {
    // Meta de 8h, prazo 16:00: às 13:00 foram consumidas 5h e restam 3h.
    $ticket = slaTicket();
    $now = Carbon::parse('2026-10-21 13:00:00');

    expect(SlaClock::consumedResolutionSeconds($ticket, $now))->toBe(5 * 3600)
        ->and(SlaClock::remainingResolutionSeconds($ticket, $now))->toBe(3 * 3600);
});

test('an active pause freezes the clock at its start', function () {
    // Pausa iniciada às 11:00 (3h consumidas); às 15:00 continuam 3h consumidas e 5h restantes.
    $ticket = slaTicket(['status' => TicketStatus::Pending], Carbon::parse('2026-10-21 11:00:00'));
    $now = Carbon::parse('2026-10-21 15:00:00');

    expect(SlaClock::consumedResolutionSeconds($ticket, $now))->toBe(3 * 3600)
        ->and(SlaClock::remainingResolutionSeconds($ticket, $now))->toBe(5 * 3600);
});

test('closed pauses are already embedded in the extended deadline', function () {
    // Aberto às 08:00. Duas pausas de 1h e 2h estenderam o prazo de 16:00 para 19:00. Às 14:00 se
    // passaram 6h, das quais 3h paradas: 3h consumidas (8h de meta − 5h restantes).
    $ticket = slaTicket(['resolution_due_at' => Carbon::parse('2026-10-21 19:00:00')]);
    $now = Carbon::parse('2026-10-21 14:00:00');

    expect(SlaClock::consumedResolutionSeconds($ticket, $now))->toBe(3 * 3600)
        ->and(SlaClock::remainingResolutionSeconds($ticket, $now))->toBe(5 * 3600);
});

test('an overdue ticket has negative remaining time and consumed time above the target', function () {
    $ticket = slaTicket();
    $now = Carbon::parse('2026-10-21 17:30:00');

    expect(SlaClock::remainingResolutionSeconds($ticket, $now))->toBe(-5400)
        ->and(SlaClock::consumedResolutionSeconds($ticket, $now))->toBe(8 * 3600 + 5400);
});

test('consumed time is never negative', function () {
    // Prazo estendido além da meta (por exemplo, uma pausa em andamento no instante da abertura).
    $ticket = slaTicket(['resolution_due_at' => Carbon::parse('2026-10-21 20:00:00')]);

    expect(SlaClock::consumedResolutionSeconds($ticket, Carbon::parse('2026-10-21 10:00:00')))->toBe(0);
});

test('a finished ticket keeps the time of its resolution', function () {
    $ticket = slaTicket([
        'status' => TicketStatus::Resolved,
        'resolved_at' => Carbon::parse('2026-10-21 14:00:00'),
    ]);
    $later = Carbon::parse('2026-10-25 09:00:00');

    expect(SlaClock::consumedResolutionSeconds($ticket, $later))->toBe(6 * 3600)
        ->and(SlaClock::remainingResolutionSeconds($ticket, $later))->toBe(2 * 3600);
});

test('a paused ticket without a recorded pause follows the given instant', function () {
    $ticket = slaTicket(['status' => TicketStatus::Pending]);

    expect(SlaClock::remainingResolutionSeconds($ticket, Carbon::parse('2026-10-21 15:00:00')))->toBe(3600);
});
