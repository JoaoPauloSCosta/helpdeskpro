<?php

use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\EditTicketComment;
use App\Actions\Tickets\RegisterTicketPending;
use App\Enums\SlaState;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->requester = User::factory()->withRole('requester')->create();
    $this->tech = User::factory()->withRole('technician')->create();
    $this->manager = User::factory()->withRole('manager')->create();

    // Aberto às 08:00, meta de 30 min de primeira resposta e 8h de resolução.
    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::High,
        'requester_id' => $this->requester->id,
        'assigned_to' => $this->tech->id,
        'sla_first_response_minutes' => 30,
        'sla_resolution_minutes' => 480,
        'first_response_due_at' => '2026-10-21 08:30:00',
        'resolution_due_at' => '2026-10-21 16:00:00',
    ]);
});

function commentOn(User $user, Ticket $ticket, string $visibility = 'public'): void
{
    test()->actingAs($user)
        ->post("/tickets/{$ticket->ticket_number}/comments", ['body' => 'Mensagem de teste', 'visibility' => $visibility])
        ->assertSessionHasNoErrors();
}

function breachEvents(Ticket $ticket)
{
    return $ticket->histories()->where('event_type', TicketEvent::SlaBreached)->get();
}

test('the first public comment from the support team records the first response', function () {
    $this->travelTo(Carbon::parse('2026-10-21 08:10:00'));

    commentOn($this->tech, $this->ticket);

    $ticket = $this->ticket->fresh();

    expect($ticket->first_response_at->toDateTimeString())->toBe('2026-10-21 08:10:00')
        ->and($ticket->first_response_breached_at)->toBeNull()
        ->and($ticket->firstResponseSlaState())->toBe(SlaState::Met)
        ->and(breachEvents($ticket))->toHaveCount(0);
});

test('an internal note does not count as first response', function () {
    commentOn($this->tech, $this->ticket, 'internal');

    expect($this->ticket->fresh()->first_response_at)->toBeNull();
});

test('a comment from the requester does not count as first response', function () {
    commentOn($this->requester, $this->ticket);

    expect($this->ticket->fresh()->first_response_at)->toBeNull();
});

test('support staff who is also the requester does not count as first response (D1)', function () {
    $own = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'requester_id' => $this->tech->id,
        'assigned_to' => $this->tech->id,
        'sla_first_response_minutes' => 30,
        'first_response_due_at' => '2026-10-21 08:30:00',
    ]);

    commentOn($this->tech, $own);

    expect($own->fresh()->first_response_at)->toBeNull();
});

test('the second support comment does not change the first response', function () {
    $this->travelTo(Carbon::parse('2026-10-21 08:05:00'));
    commentOn($this->tech, $this->ticket);

    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    commentOn($this->manager, $this->ticket);

    expect($this->ticket->fresh()->first_response_at->toDateTimeString())->toBe('2026-10-21 08:05:00');
});

test('editing a comment does not record the first response', function () {
    commentOn($this->tech, $this->ticket, 'internal');
    $note = TicketComment::sole();

    $this->travelTo(Carbon::parse('2026-10-21 08:20:00'));
    app(EditTicketComment::class)($this->tech, $note, 'Texto corrigido');

    expect($this->ticket->fresh()->first_response_at)->toBeNull();
});

test('a response after the deadline records the breach once and shows as overdue', function () {
    $this->travelTo(Carbon::parse('2026-10-21 08:45:00'));

    commentOn($this->tech, $this->ticket);

    $ticket = $this->ticket->fresh();
    $events = breachEvents($ticket);

    expect($ticket->first_response_at->toDateTimeString())->toBe('2026-10-21 08:45:00')
        ->and($ticket->first_response_breached_at->toDateTimeString())->toBe('2026-10-21 08:45:00')
        ->and($ticket->resolution_breached_at)->toBeNull()
        ->and($ticket->firstResponseSlaState())->toBe(SlaState::Overdue)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->user_id)->toBeNull()
        ->and($events[0]->new_values)->toBe([
            'indicator' => 'first_response',
            'due_at' => '2026-10-21T08:30:00+00:00',
            'breached_at' => '2026-10-21T08:45:00+00:00',
        ]);

    // Outro comentário depois não duplica a violação.
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    commentOn($this->tech, $this->ticket);

    expect(breachEvents($this->ticket))->toHaveCount(1);
});

test('a comment on an unanswered overdue ticket records the breach even when it is not a first response', function () {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));

    commentOn($this->requester, $this->ticket);

    $ticket = $this->ticket->fresh();

    expect($ticket->first_response_at)->toBeNull()
        ->and($ticket->first_response_breached_at)->not->toBeNull()
        ->and(breachEvents($ticket))->toHaveCount(1);
});

test('a comment after the resolution deadline records the resolution breach', function () {
    $this->ticket->forceFill(['first_response_at' => '2026-10-21 08:10:00'])->save();
    $this->travelTo(Carbon::parse('2026-10-21 17:00:00'));

    commentOn($this->tech, $this->ticket);

    $ticket = $this->ticket->fresh();
    $events = breachEvents($ticket);

    expect($ticket->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and($ticket->first_response_breached_at)->toBeNull()
        ->and($events)->toHaveCount(1)
        ->and($events[0]->new_values['indicator'])->toBe('resolution');
});

test('a ticket without deadlines never records a first response breach', function () {
    $free = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'requester_id' => $this->requester->id,
        'assigned_to' => $this->tech->id,
    ]);
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00'));

    commentOn($this->tech, $free);

    $free = $free->fresh();

    expect($free->first_response_at)->not->toBeNull()
        ->and($free->first_response_breached_at)->toBeNull()
        ->and($free->resolution_breached_at)->toBeNull()
        ->and(breachEvents($free))->toHaveCount(0);
});

test('a later pause and resume do not change the first response result', function () {
    $this->travelTo(Carbon::parse('2026-10-21 08:10:00'));
    commentOn($this->tech, $this->ticket);

    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    app(RegisterTicketPending::class)($this->tech, $this->ticket->fresh(), TicketStatus::InProgress, TicketStatus::Pending, 'Aguardando peça.');
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    app(ChangeTicketStatus::class)($this->tech, $this->ticket->fresh(), TicketStatus::Pending, TicketStatus::InProgress);

    $ticket = $this->ticket->fresh();

    expect($ticket->first_response_at->toDateTimeString())->toBe('2026-10-21 08:10:00')
        ->and($ticket->first_response_due_at->toDateTimeString())->toBe('2026-10-21 08:30:00')
        ->and($ticket->first_response_breached_at)->toBeNull()
        ->and($ticket->firstResponseSlaState())->toBe(SlaState::Met);
});

test('a failure while recording the SLA leaves no comment behind', function () {
    // A falha vem da gravação da primeira resposta, depois de a mensagem já ter sido salva.
    Ticket::updating(fn () => throw new RuntimeException('Falha simulada'));

    $this->actingAs($this->tech)->withoutExceptionHandling();

    expect(fn () => $this->post("/tickets/{$this->ticket->ticket_number}/comments", ['body' => 'x']))
        ->toThrow(RuntimeException::class);

    expect(TicketComment::count())->toBe(0)
        ->and($this->ticket->histories()->count())->toBe(0);
});
