<?php

use App\Enums\SlaState;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = User::factory()->withRole('manager')->create();

    // Meta de 10 h (600 min) e 30 min de primeira resposta; aberto às 08:00. Em risco a partir de 80%: 16:00.
    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'sla_first_response_minutes' => 100,
        'sla_resolution_minutes' => 600,
        'first_response_due_at' => '2026-10-21 09:40:00',
        'resolution_due_at' => '2026-10-21 18:00:00',
    ]);
});

function riskState(Ticket $ticket, string $at): SlaState
{
    return $ticket->fresh()->slaState(Carbon::parse($at));
}

test('resolution is on track until 80 percent of the target is consumed', function () {
    expect(riskState($this->ticket, '2026-10-21 08:00:00'))->toBe(SlaState::OnTrack)
        ->and(riskState($this->ticket, '2026-10-21 15:59:59'))->toBe(SlaState::OnTrack)
        // 80% de 10 h = 8 h: exatamente às 16:00.
        ->and(riskState($this->ticket, '2026-10-21 16:00:00'))->toBe(SlaState::AtRisk)
        ->and(riskState($this->ticket, '2026-10-21 18:00:00'))->toBe(SlaState::AtRisk)
        ->and(riskState($this->ticket, '2026-10-21 18:00:01'))->toBe(SlaState::Overdue);
});

test('the first response becomes at risk at 80 percent of its own target', function () {
    // 80% de 100 min = 80 min: 09:20.
    $at = fn (string $time) => $this->ticket->fresh()->firstResponseSlaState(Carbon::parse("2026-10-21 {$time}"));

    expect($at('09:19:59'))->toBe(SlaState::OnTrack)
        ->and($at('09:20:00'))->toBe(SlaState::AtRisk)
        ->and($at('09:40:01'))->toBe(SlaState::Overdue);
});

test('an answered first response is met, never at risk', function () {
    $this->ticket->forceFill(['first_response_at' => '2026-10-21 09:30:00'])->save();

    expect($this->ticket->fresh()->firstResponseSlaState(Carbon::parse('2026-10-21 09:35:00')))->toBe(SlaState::Met);
});

test('paused, resolved and unmeasurable tickets are never at risk', function () {
    $paused = Ticket::factory()->create([
        'status' => TicketStatus::Pending,
        'sla_resolution_minutes' => 600,
        'resolution_due_at' => '2026-10-21 18:00:00',
    ]);
    $noTarget = Ticket::factory()->create(['status' => TicketStatus::InProgress, 'resolution_due_at' => '2026-10-21 18:00:00']);
    $resolved = Ticket::factory()->create([
        'status' => TicketStatus::Resolved,
        'sla_resolution_minutes' => 600,
        'resolution_due_at' => '2026-10-21 18:00:00',
        'resolved_at' => '2026-10-21 17:00:00',
    ]);

    expect(riskState($paused, '2026-10-21 17:00:00'))->toBe(SlaState::Paused)
        ->and(riskState($noTarget, '2026-10-21 17:00:00'))->toBe(SlaState::OnTrack)
        ->and(riskState($resolved, '2026-10-21 17:30:00'))->toBe(SlaState::Met);
});

test('the SLA filter splits on track and at risk exactly like the state', function () {
    $early = $this->ticket;
    $late = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'created_at' => '2026-10-21 05:00:00',
        'sla_resolution_minutes' => 600,
        'resolution_due_at' => '2026-10-21 16:30:00',
    ]);
    $overdue = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'sla_resolution_minutes' => 60,
        'resolution_due_at' => '2026-10-21 08:00:00',
    ]);
    $noTarget = Ticket::factory()->create(['status' => TicketStatus::Open, 'resolution_due_at' => '2026-10-21 20:00:00']);

    // 16:00: `early` (prazo 18:00) está no limite dos 80%; `late` (prazo 16:30) já passou deles.
    $this->travelTo(Carbon::parse('2026-10-21 16:00:00'));

    $numbers = function (string $state) {
        $numbers = [];
        $this->actingAs($this->manager)->get("/tickets?sla={$state}")->assertOk()
            ->assertInertia(function (Assert $page) use (&$numbers) {
                $numbers = collect($page->toArray()['props']['tickets']['data'])->pluck('number')->all();

                return $page;
            });

        return $numbers;
    };

    // Sem meta aplicada, o chamado nunca está em risco.
    expect($numbers('at_risk'))->toEqualCanonicalizing([$early->ticket_number, $late->ticket_number])
        ->and($numbers('on_track'))->toBe([$noTarget->ticket_number])
        ->and($numbers('overdue'))->toBe([$overdue->ticket_number]);

    // Cada chamado listado tem a mesma situação que o filtro usou.
    foreach ([$early, $late, $overdue, $noTarget] as $ticket) {
        $listed = collect(['at_risk', 'on_track', 'overdue'])->first(fn ($state) => in_array($ticket->ticket_number, $numbers($state), true));

        expect($ticket->fresh()->slaState(now())->value)->toBe($listed);
    }
});

test('the filter exposes the at risk option', function () {
    $this->actingAs($this->manager)->get('/tickets')->assertInertia(fn (Assert $page) => $page
        ->where('options.slaStates', fn ($options) => collect($options)->contains(fn ($option) => $option['value'] === 'at_risk' && $option['label'] === 'Em risco')));
});
