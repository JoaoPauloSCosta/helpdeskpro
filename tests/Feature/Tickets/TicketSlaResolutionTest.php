<?php

use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CloseTicket;
use App\Actions\Tickets\RegisterTicketPending;
use App\Actions\Tickets\ReopenTicket;
use App\Actions\Tickets\ResolveTicket;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->requester = User::factory()->withRole('requester')->create();
    $this->tech = User::factory()->withRole('technician')->create();
    $this->manager = User::factory()->withRole('manager')->create();

    // Aberto às 08:00: primeira resposta em 30 min (dada às 08:10) e resolução em 8h (16:00).
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

function resolveAt(string $at): Ticket
{
    test()->travelTo(Carbon::parse($at));
    $ticket = test()->ticket->fresh();

    // Depois de uma reabertura o chamado volta como Aberto; a resolução parte de Em atendimento.
    if ($ticket->status === TicketStatus::Open) {
        app(ChangeTicketStatus::class)(test()->tech, $ticket, TicketStatus::Open, TicketStatus::InProgress);
        $ticket = $ticket->fresh();
    }

    app(ResolveTicket::class)(test()->tech, $ticket, $ticket->status, 'Problema corrigido.');

    return test()->ticket->fresh();
}

function reopenAt(string $at): Ticket
{
    test()->travelTo(Carbon::parse($at));
    $ticket = test()->ticket->fresh();
    app(ReopenTicket::class)(test()->requester, $ticket, $ticket->status, 'O problema voltou.');

    return test()->ticket->fresh();
}

function resolutionBreaches(Ticket $ticket)
{
    return $ticket->histories()->where('event_type', TicketEvent::SlaBreached)->get();
}

test('resolving within the deadline records the effective time and no breach', function () {
    $ticket = resolveAt('2026-10-21 13:00:00');

    expect($ticket->resolution_effective_seconds)->toBe(5 * 3600)
        ->and($ticket->resolution_breached_at)->toBeNull()
        ->and(resolutionBreaches($ticket))->toHaveCount(0)
        ->and($ticket->slaState()->value)->toBe('met');
});

test('resolving after the deadline records the breach once', function () {
    $ticket = resolveAt('2026-10-21 17:00:00');
    $breaches = resolutionBreaches($ticket);

    expect($ticket->resolution_effective_seconds)->toBe(9 * 3600)
        ->and($ticket->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and($ticket->first_response_breached_at)->toBeNull()
        ->and($breaches)->toHaveCount(1)
        ->and($breaches[0]->new_values['indicator'])->toBe('resolution')
        ->and($ticket->slaState()->value)->toBe('overdue');
});

test('paused time is not part of the effective time', function () {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    app(RegisterTicketPending::class)($this->tech, $this->ticket->fresh(), TicketStatus::InProgress, TicketStatus::Pending, 'Aguardando peça.');
    $this->travelTo(Carbon::parse('2026-10-21 11:00:00'));
    app(ChangeTicketStatus::class)($this->tech, $this->ticket->fresh(), TicketStatus::Pending, TicketStatus::InProgress);

    // 08:00 a 14:00 são 6h, com 2h paradas.
    $ticket = resolveAt('2026-10-21 14:00:00');

    expect($ticket->resolution_effective_seconds)->toBe(4 * 3600);
});

test('the resolution event records the effective time', function () {
    resolveAt('2026-10-21 13:00:00');

    $event = $this->ticket->histories()->where('event_type', TicketEvent::Resolved)->sole();

    expect($event->new_values['resolution_effective_seconds'])->toBe(5 * 3600);
});

test('a ticket without an applied target has no effective time and no breach', function () {
    $this->ticket->forceFill([
        'sla_first_response_minutes' => null,
        'sla_resolution_minutes' => null,
        'first_response_due_at' => null,
        'resolution_due_at' => null,
    ])->save();

    $ticket = resolveAt('2026-10-25 13:00:00');

    expect($ticket->resolution_effective_seconds)->toBeNull()
        ->and($ticket->resolution_breached_at)->toBeNull()
        ->and(resolutionBreaches($ticket))->toHaveCount(0);
});

test('the time in resolved does not count after a reopening (D3)', function () {
    // Resolvido às 12:00 (4h consumidas), reaberto às 14:00: 2h em Resolvido estendem o prazo de 16:00 para 18:00.
    resolveAt('2026-10-21 12:00:00');
    $ticket = reopenAt('2026-10-21 14:00:00');
    $event = $ticket->histories()->where('event_type', TicketEvent::Reopened)->sole();

    expect($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 18:00:00')
        ->and($event->new_values['resolution_due_before'])->toBe('2026-10-21T16:00:00+00:00')
        ->and($event->new_values['resolution_due_at'])->toBe('2026-10-21T18:00:00+00:00');
});

test('a late reopening does not come back overdue', function () {
    resolveAt('2026-10-21 12:00:00');
    $ticket = reopenAt('2026-10-24 12:00:00');

    // 3 dias em Resolvido: o prazo anda 3 dias e as 4h restantes seguem valendo.
    expect($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-24 16:00:00')
        ->and($ticket->slaState(now())->value)->toBe('on_track');
});

test('resolving again adds the cycles in the effective time', function () {
    resolveAt('2026-10-21 12:00:00');
    reopenAt('2026-10-21 14:00:00');
    $ticket = resolveAt('2026-10-21 16:00:00');

    // 4h no primeiro ciclo + 2h no segundo; o prazo (18:00) foi cumprido.
    expect($ticket->resolution_effective_seconds)->toBe(6 * 3600)
        ->and($ticket->resolution_breached_at)->toBeNull()
        ->and($ticket->slaState()->value)->toBe('met');
});

test('a breach recorded before a reopening is preserved and never duplicated', function () {
    $resolved = resolveAt('2026-10-21 17:00:00');
    $firstBreach = $resolved->resolution_breached_at;
    reopenAt('2026-10-21 18:00:00');
    $ticket = resolveAt('2026-10-21 23:00:00');

    expect($ticket->resolution_breached_at->equalTo($firstBreach))->toBeTrue()
        ->and(resolutionBreaches($ticket))->toHaveCount(1);
});

test('reopening does not restart targets, first response or earlier breaches', function () {
    $this->ticket->forceFill(['first_response_breached_at' => '2026-10-21 08:31:00'])->save();
    resolveAt('2026-10-21 12:00:00');
    $ticket = reopenAt('2026-10-21 14:00:00');

    expect($ticket->sla_first_response_minutes)->toBe(30)
        ->and($ticket->sla_resolution_minutes)->toBe(480)
        ->and($ticket->first_response_due_at->toDateTimeString())->toBe('2026-10-21 08:30:00')
        ->and($ticket->first_response_at->toDateTimeString())->toBe('2026-10-21 08:10:00')
        ->and($ticket->first_response_breached_at->toDateTimeString())->toBe('2026-10-21 08:31:00');
});

test('closing changes neither deadline, effective time nor breaches', function () {
    $resolved = resolveAt('2026-10-21 17:00:00');
    $this->travelTo(Carbon::parse('2026-10-22 09:00:00'));
    app(CloseTicket::class)($this->manager, $resolved, $resolved->status);

    $closed = $this->ticket->fresh();

    expect($closed->status)->toBe(TicketStatus::Closed)
        ->and($closed->resolution_due_at->equalTo($resolved->resolution_due_at))->toBeTrue()
        ->and($closed->resolution_effective_seconds)->toBe($resolved->resolution_effective_seconds)
        ->and($closed->resolution_breached_at->equalTo($resolved->resolution_breached_at))->toBeTrue()
        ->and(resolutionBreaches($closed))->toHaveCount(1);
});
