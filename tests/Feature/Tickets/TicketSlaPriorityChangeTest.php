<?php

use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\RegisterTicketPending;
use App\Actions\Tickets\ResolveTicket;
use App\Actions\Tickets\UpdateTicketAttributes;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->requester = User::factory()->withRole('requester')->create();
    $this->tech = User::factory()->withRole('technician')->create();

    // Média não tem política (D2).
    $this->high = SlaPolicy::factory()->create(['priority' => 'high', 'first_response_minutes' => 30, 'resolution_minutes' => 480]);
    $this->critical = SlaPolicy::factory()->create(['priority' => 'critical', 'first_response_minutes' => 15, 'resolution_minutes' => 240]);
    $this->low = SlaPolicy::factory()->create(['priority' => 'low', 'first_response_minutes' => 60, 'resolution_minutes' => 720]);

    // Aberto às 08:00 com a política de Alta: 30 min de primeira resposta e 8h de resolução (16:00).
    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::High,
        'requester_id' => $this->requester->id,
        'assigned_to' => $this->tech->id,
        'sla_policy_id' => $this->high->id,
        'sla_first_response_minutes' => 30,
        'sla_resolution_minutes' => 480,
        'first_response_due_at' => '2026-10-21 08:30:00',
        'resolution_due_at' => '2026-10-21 16:00:00',
    ]);
});

function changePriorityAt(string $at, string $priority, ?Ticket $ticket = null): Ticket
{
    $ticket ??= test()->ticket;
    test()->travelTo(Carbon::parse($at));
    $current = $ticket->fresh();
    app(UpdateTicketAttributes::class)(test()->tech, $current, ['priority' => $priority], $current->editVersion());

    return $ticket->fresh();
}

function slaChangeEvents(Ticket $ticket)
{
    return $ticket->histories()->where('event_type', TicketEvent::SlaTargetsChanged)->orderBy('id')->get();
}

function slaBreachEvents(Ticket $ticket)
{
    return $ticket->histories()->where('event_type', TicketEvent::SlaBreached)->get();
}

test('a shorter target below the time already consumed makes the ticket overdue (RF-16.4)', function () {
    // Meta de 8h, 5h consumidas às 13:00, troca para 4h: venceu há 1h.
    $this->ticket->forceFill(['first_response_at' => '2026-10-21 08:10:00'])->save();

    $ticket = changePriorityAt('2026-10-21 13:00:00', 'critical');

    expect($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 12:00:00')
        ->and($ticket->slaState()->value)->toBe('overdue')
        ->and($ticket->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 13:00:00')
        ->and($ticket->sla_resolution_minutes)->toBe(240)
        ->and($ticket->sla_policy_id)->toBe($this->critical->id)
        ->and(slaBreachEvents($ticket))->toHaveCount(1);
});

test('a longer target keeps the consumed time and leaves the remaining time (RF-16.4)', function () {
    // Troca para 12h com 5h consumidas: restam 7h.
    $ticket = changePriorityAt('2026-10-21 13:00:00', 'low');

    expect($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 20:00:00')
        ->and($ticket->sla_resolution_minutes)->toBe(720)
        ->and($ticket->slaState()->value)->toBe('on_track')
        ->and($ticket->resolution_breached_at)->toBeNull();
});

test('the change records the priority and targets before and after, with the consumed time', function () {
    changePriorityAt('2026-10-21 13:00:00', 'low');

    $event = slaChangeEvents($this->ticket)->sole();

    expect($event->user_id)->toBe($this->tech->id)
        ->and($event->old_values)->toBe([
            'priority' => ['priority' => 'high', 'label' => 'Alta'],
            'policy_id' => $this->high->id,
            'first_response_minutes' => 30,
            'resolution_minutes' => 480,
            'first_response_due_at' => '2026-10-21T08:30:00+00:00',
            'resolution_due_at' => '2026-10-21T16:00:00+00:00',
        ])
        ->and($event->new_values)->toBe([
            'priority' => ['priority' => 'low', 'label' => 'Baixa'],
            'policy_id' => $this->low->id,
            'first_response_minutes' => 60,
            'resolution_minutes' => 720,
            'first_response_due_at' => '2026-10-21T09:00:00+00:00',
            'resolution_due_at' => '2026-10-21T20:00:00+00:00',
            'consumed_seconds' => 5 * 3600,
        ]);
});

test('pauses already closed stay excluded from the consumed time', function () {
    // Pausa de 09:00 a 10:00 leva o prazo a 17:00. Às 13:00: 4h consumidas. Para 12h: restam 8h.
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    app(RegisterTicketPending::class)($this->tech, $this->ticket->fresh(), TicketStatus::InProgress, TicketStatus::Pending, 'Aguardando peça.');
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    app(ChangeTicketStatus::class)($this->tech, $this->ticket->fresh(), TicketStatus::Pending, TicketStatus::InProgress);

    $ticket = changePriorityAt('2026-10-21 13:00:00', 'low');

    expect(slaChangeEvents($ticket)->sole()->new_values['consumed_seconds'])->toBe(4 * 3600)
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 21:00:00');
});

test('a paused ticket counts the remaining time from the start of the pause', function () {
    // Pausa às 11:00 (3h consumidas). Às 15:00, ainda pausado, troca para 12h: restam 9h a partir das 11:00.
    $this->travelTo(Carbon::parse('2026-10-21 11:00:00'));
    app(RegisterTicketPending::class)($this->tech, $this->ticket->fresh(), TicketStatus::InProgress, TicketStatus::Pending, 'Aguardando peça.');

    $ticket = changePriorityAt('2026-10-21 15:00:00', 'low');

    expect(slaChangeEvents($ticket)->sole()->new_values['consumed_seconds'])->toBe(3 * 3600)
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 20:00:00')
        ->and($ticket->resolution_breached_at)->toBeNull();

    // Retomada às 16:00: a pausa de 5h estende o prazo; o tempo parado não conta.
    $this->travelTo(Carbon::parse('2026-10-21 16:00:00'));
    app(ChangeTicketStatus::class)($this->tech, $ticket, TicketStatus::Pending, TicketStatus::InProgress);

    expect($this->ticket->fresh()->resolution_due_at->toDateTimeString())->toBe('2026-10-22 01:00:00');
});

test('several changes with a pause in the middle keep the consumed time', function () {
    // 09:00 Alta→Crítica: 1h consumida, prazo 09:00 + 3h = 12:00.
    changePriorityAt('2026-10-21 09:00:00', 'critical');
    expect($this->ticket->fresh()->resolution_due_at->toDateTimeString())->toBe('2026-10-21 12:00:00');

    // Pausa de 10:00 a 11:00: prazo 13:00.
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    app(RegisterTicketPending::class)($this->tech, $this->ticket->fresh(), TicketStatus::InProgress, TicketStatus::Pending, 'Aguardando peça.');
    $this->travelTo(Carbon::parse('2026-10-21 11:00:00'));
    app(ChangeTicketStatus::class)($this->tech, $this->ticket->fresh(), TicketStatus::Pending, TicketStatus::InProgress);

    // 12:00 Crítica→Baixa: 3h consumidas (4h de meta − 1h restante), prazo 12:00 + 9h = 21:00.
    $ticket = changePriorityAt('2026-10-21 12:00:00', 'low');

    expect(slaChangeEvents($ticket)->last()->new_values['consumed_seconds'])->toBe(3 * 3600)
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 21:00:00')
        ->and(slaChangeEvents($ticket))->toHaveCount(2);
});

test('before the first response the first response deadline follows the new target', function () {
    $ticket = changePriorityAt('2026-10-21 08:20:00', 'low');

    expect($ticket->first_response_due_at->toDateTimeString())->toBe('2026-10-21 09:00:00')
        ->and($ticket->sla_first_response_minutes)->toBe(60);
});

test('after the first response the result is preserved and not recalculated', function () {
    $this->ticket->forceFill(['first_response_at' => '2026-10-21 08:10:00'])->save();

    $ticket = changePriorityAt('2026-10-21 08:20:00', 'critical');

    expect($ticket->first_response_due_at->toDateTimeString())->toBe('2026-10-21 08:30:00')
        ->and($ticket->sla_first_response_minutes)->toBe(30)
        ->and($ticket->first_response_at->toDateTimeString())->toBe('2026-10-21 08:10:00')
        ->and($ticket->firstResponseSlaState()->value)->toBe('met');
});

test('an overdue first response is recorded as a breach and survives a longer target', function () {
    // Sem resposta às 09:00 (prazo 08:30). A troca para 60 min (prazo 09:00) não apaga a violação.
    $ticket = changePriorityAt('2026-10-21 09:00:00', 'low');
    $breaches = slaBreachEvents($ticket);

    expect($ticket->first_response_breached_at->toDateTimeString())->toBe('2026-10-21 09:00:00')
        ->and($breaches)->toHaveCount(1)
        ->and($breaches[0]->new_values['indicator'])->toBe('first_response')
        ->and($breaches[0]->new_values['due_at'])->toBe('2026-10-21T08:30:00+00:00');
});

test('a resolution violation stays recorded when the new target is longer', function () {
    // Venceu às 16:00 sem registro. Às 17:00 (9h consumidas), a troca para 12h registra a violação e deixa 3h.
    $this->ticket->forceFill(['first_response_at' => '2026-10-21 08:10:00'])->save();

    $ticket = changePriorityAt('2026-10-21 17:00:00', 'low');

    expect($ticket->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 20:00:00')
        ->and($ticket->slaState()->value)->toBe('on_track')
        ->and(slaBreachEvents($ticket))->toHaveCount(1);

    // Outra troca não duplica nem apaga a violação.
    $ticket = changePriorityAt('2026-10-21 18:00:00', 'critical');

    expect($ticket->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and(slaBreachEvents($ticket)->where('new_values.indicator', 'resolution'))->toHaveCount(1);
});

test('a priority without an active policy leaves the ticket without deadlines and records it (D2)', function () {
    $this->ticket->forceFill(['first_response_at' => '2026-10-21 08:10:00'])->save();

    $ticket = changePriorityAt('2026-10-21 13:00:00', 'medium');
    $event = slaChangeEvents($ticket)->sole();

    expect($ticket->sla_policy_id)->toBeNull()
        ->and($ticket->sla_resolution_minutes)->toBeNull()
        ->and($ticket->resolution_due_at)->toBeNull()
        ->and($ticket->slaState()->value)->toBe('none')
        ->and($event->new_values['policy_id'])->toBeNull()
        ->and($event->new_values['resolution_minutes'])->toBeNull()
        ->and($event->new_values['resolution_due_at'])->toBeNull()
        ->and($event->old_values['resolution_minutes'])->toBe(480);
});

test('an earlier violation stays when the new priority has no policy', function () {
    $ticket = changePriorityAt('2026-10-21 17:00:00', 'medium');

    expect($ticket->resolution_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and($ticket->first_response_breached_at->toDateTimeString())->toBe('2026-10-21 17:00:00')
        ->and($ticket->resolution_due_at)->toBeNull();
});

test('a ticket opened without policy receives the new policy keeping the time already consumed', function () {
    $free = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::Medium,
        'requester_id' => $this->requester->id,
        'assigned_to' => $this->tech->id,
        'created_at' => '2026-10-21 08:00:00',
    ]);

    $ticket = changePriorityAt('2026-10-21 13:00:00', 'high', $free);

    // 5h consumidas desde a abertura; meta de 8h: restam 3h.
    expect($ticket->sla_policy_id)->toBe($this->high->id)
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 16:00:00')
        ->and($ticket->first_response_due_at->toDateTimeString())->toBe('2026-10-21 08:30:00')
        ->and(slaChangeEvents($ticket)->sole()->new_values['consumed_seconds'])->toBe(5 * 3600);
});

test('a priority change without any policy before or after touches no SLA data', function () {
    $free = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::Medium,
        'requester_id' => $this->requester->id,
        'assigned_to' => $this->tech->id,
    ]);
    SlaPolicy::query()->where('priority', 'low')->delete();

    $ticket = changePriorityAt('2026-10-21 13:00:00', 'low', $free);

    expect($ticket->priority)->toBe(TicketPriority::Low)
        ->and($ticket->resolution_due_at)->toBeNull()
        ->and(slaChangeEvents($ticket))->toHaveCount(0);
});

test('resolved tickets are not recalculated', function () {
    $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
    app(ResolveTicket::class)($this->tech, $this->ticket->fresh(), TicketStatus::InProgress, 'Problema corrigido.');
    $resolved = $this->ticket->fresh();

    $ticket = changePriorityAt('2026-10-21 13:00:00', 'critical');

    expect($ticket->priority)->toBe(TicketPriority::Critical)
        ->and($ticket->resolution_due_at->equalTo($resolved->resolution_due_at))->toBeTrue()
        ->and($ticket->sla_resolution_minutes)->toBe(480)
        ->and($ticket->resolution_effective_seconds)->toBe($resolved->resolution_effective_seconds)
        ->and(slaChangeEvents($ticket))->toHaveCount(0);
});

test('changing only the category does not recalculate the SLA', function () {
    $this->travelTo(Carbon::parse('2026-10-21 13:00:00'));
    $category = TicketCategory::factory()->create();
    $current = $this->ticket->fresh();

    app(UpdateTicketAttributes::class)($this->tech, $current, ['category_id' => $category->id], $current->editVersion());

    $ticket = $this->ticket->fresh();

    expect($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 16:00:00')
        ->and(slaChangeEvents($ticket))->toHaveCount(0)
        ->and(slaBreachEvents($ticket))->toHaveCount(0);
});

test('a failure while recording the events leaves the priority and the deadlines untouched', function () {
    $this->travelTo(Carbon::parse('2026-10-21 13:00:00'));
    $current = $this->ticket->fresh();
    TicketHistory::creating(fn () => throw new RuntimeException('Falha simulada'));

    expect(fn () => app(UpdateTicketAttributes::class)($this->tech, $current, ['priority' => 'low'], $current->editVersion()))
        ->toThrow(RuntimeException::class);

    $ticket = $this->ticket->fresh();

    expect($ticket->priority)->toBe(TicketPriority::High)
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 16:00:00')
        ->and($ticket->sla_resolution_minutes)->toBe(480);
});
