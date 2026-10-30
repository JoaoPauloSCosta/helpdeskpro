<?php

use App\Actions\Tickets\ReopenTicket;
use App\Actions\Tickets\ResolveTicket;
use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));

    $this->tech = User::factory()->withRole('technician')->create();
    $this->requester = User::factory()->withRole('requester')->create();
});

function resolvedTicket(): Ticket
{
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'assigned_to' => test()->tech->id,
        'requester_id' => test()->requester->id,
    ]);
    app(ResolveTicket::class)(test()->tech, $ticket, TicketStatus::InProgress, 'Problema corrigido.');

    return $ticket->fresh();
}

function runAutoClose(string $at): void
{
    test()->travelTo(Carbon::parse($at));
    test()->artisan('tickets:close-resolved')->assertSuccessful();
}

test('the command is scheduled', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'tickets:close-resolved'));

    expect($event)->not->toBeNull()->and($event->withoutOverlapping)->toBeTrue();
});

test('a resolved ticket is closed once 3 calendar days have passed', function () {
    $ticket = resolvedTicket();

    runAutoClose('2026-10-23 10:00:00');

    $fresh = $ticket->fresh();
    expect($fresh->status)->toBe(TicketStatus::Closed)
        ->and($fresh->closed_at->toDateTimeString())->toBe('2026-10-23 10:00:00')
        ->and($fresh->resolved_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($fresh->resolution_summary)->toBe('Problema corrigido.');

    $event = $ticket->histories()->where('event_type', TicketEvent::Closed)->sole();
    expect($event->user_id)->toBeNull()
        ->and($event->new_values['automatic'])->toBeTrue()
        ->and($event->old_values['status'])->toBe('resolved');
});

test('a resolved ticket is kept before the 3 days', function () {
    $ticket = resolvedTicket();

    runAutoClose('2026-10-23 09:59:59');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->fresh()->closed_at)->toBeNull()
        ->and($ticket->histories()->where('event_type', TicketEvent::Closed)->count())->toBe(0);
});

test('tickets in other statuses are never closed', function (TicketStatus $status) {
    $ticket = Ticket::factory()->create(['status' => $status, 'resolved_at' => '2026-10-01 10:00:00']);

    runAutoClose('2026-10-30 10:00:00');

    expect($ticket->fresh()->status)->toBe($status);
})->with([TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Pending, TicketStatus::Closed]);

test('a reopened ticket is not closed, and the period restarts when it is resolved again', function () {
    $ticket = resolvedTicket();

    $this->travelTo(Carbon::parse('2026-10-22 10:00:00'));
    app(ReopenTicket::class)($this->requester, $ticket, TicketStatus::Resolved, 'Voltou a falhar.');
    expect($ticket->fresh()->status)->toBe(TicketStatus::Open);

    runAutoClose('2026-10-24 10:00:00');
    expect($ticket->fresh()->status)->toBe(TicketStatus::Open);

    $ticket->fresh()->forceFill(['status' => TicketStatus::InProgress])->save();
    app(ResolveTicket::class)($this->tech, $ticket->fresh(), TicketStatus::InProgress, 'Corrigido de vez.');

    runAutoClose('2026-10-26 09:00:00');
    expect($ticket->fresh()->status)->toBe(TicketStatus::Resolved);

    runAutoClose('2026-10-27 10:00:00');
    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed);
});

test('running the command again does not duplicate the closing', function () {
    $ticket = resolvedTicket();

    runAutoClose('2026-10-24 10:00:00');
    runAutoClose('2026-10-25 10:00:00');

    expect($ticket->histories()->where('event_type', TicketEvent::Closed)->count())->toBe(1)
        ->and($ticket->fresh()->closed_at->toDateTimeString())->toBe('2026-10-24 10:00:00');
});
