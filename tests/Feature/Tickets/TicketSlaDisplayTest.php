<?php

use App\Actions\Tickets\RegisterTicketPending;
use App\Actions\Tickets\ResolveTicket;
use App\Actions\Tickets\UpdateTicketAttributes;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->requester = User::factory()->withRole('requester')->create(['department_id' => Department::factory()->create()->id]);
    $this->tech = User::factory()->withRole('technician')->create();
    $this->manager = User::factory()->withRole('manager')->create();

    // O solicitante abre sempre em Média (RF-05): 30 min de primeira resposta e 8h de resolução. As demais não têm política.
    $this->policy = SlaPolicy::factory()->create(['priority' => 'medium', 'first_response_minutes' => 30, 'resolution_minutes' => 480]);

    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
});

/** Abre o chamado pelo fluxo real (POST /tickets), para que os prazos venham do motor. */
function openRealTicket(string $at = '2026-10-21 08:00:00'): Ticket
{
    test()->travelTo(Carbon::parse($at));
    $category = TicketCategory::factory()->create();

    test()->actingAs(test()->requester)->post('/tickets', [
        'title' => 'Chamado de teste '.Str::random(4),
        'description' => 'Descrição do chamado.',
        'category_id' => $category->id,
        'submission_token' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    return Ticket::latest('id')->first();
}

function inertiaProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];

        return $page;
    });

    return $props;
}

function slaOf(User $user, Ticket $ticket): array
{
    return inertiaProps(test()->actingAs($user)->get("/tickets/{$ticket->ticket_number}"))['sla'];
}

test('the details page shows the target, remaining time and overdue time from the stored deadlines', function () {
    $ticket = openRealTicket();

    $this->travelTo(Carbon::parse('2026-10-21 08:10:00'));
    $sla = slaOf($this->manager, $ticket);

    expect($sla['resolution'])->toMatchArray([
        'state' => 'on_track',
        'target_minutes' => 480,
        'remaining_seconds' => 480 * 60 - 600,
        'breached_at' => null,
    ])->and($sla['firstResponse'])->toMatchArray([
        'state' => 'on_track',
        'target_minutes' => 30,
        'remaining_seconds' => 20 * 60,
    ]);

    $this->travelTo(Carbon::parse('2026-10-21 16:35:00'));
    $sla = slaOf($this->manager, $ticket);

    expect($sla['resolution'])->toMatchArray(['state' => 'overdue', 'remaining_seconds' => -35 * 60])
        ->and($sla['firstResponse'])->toMatchArray(['state' => 'overdue']);
});

test('the first response and the breach history appear on the details page', function () {
    $ticket = openRealTicket();

    $this->travelTo(Carbon::parse('2026-10-21 08:10:00'));
    $this->actingAs($this->tech)->post("/tickets/{$ticket->ticket_number}/comments", ['body' => 'Olá'])->assertSessionHasNoErrors();

    expect(slaOf($this->manager, $ticket)['firstResponse'])->toMatchArray([
        'state' => 'met',
        'met_at' => '2026-10-21T08:10:00+00:00',
        // Folga em relação ao prazo (08:30): 20 min.
        'remaining_seconds' => 20 * 60,
    ]);

    // Comentário depois do prazo de resolução registra a violação, que aparece no detalhe.
    $this->travelTo(Carbon::parse('2026-10-21 17:00:00'));
    $this->actingAs($this->tech)->post("/tickets/{$ticket->ticket_number}/comments", ['body' => 'Ainda trabalhando'])->assertSessionHasNoErrors();

    expect(slaOf($this->manager, $ticket)['resolution']['breached_at'])->toBe('2026-10-21T17:00:00+00:00');
});

test('a paused ticket shows the frozen remaining time', function () {
    $ticket = openRealTicket();
    $ticket->forceFill(['status' => TicketStatus::InProgress, 'assigned_to' => $this->tech->id])->save();

    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    app(RegisterTicketPending::class)($this->tech, $ticket->fresh(), TicketStatus::InProgress, TicketStatus::Pending, 'SEGREDO-MOTIVO-DA-PENDENCIA');

    $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
    // 2h consumidas, 6h restantes, sem andar com o relógio parado.
    expect(slaOf($this->manager, $ticket)['resolution'])->toMatchArray(['state' => 'paused', 'remaining_seconds' => 6 * 3600]);

    // O solicitante não recebe SLA, nem o motivo da pendência, na página ou no histórico de SLA (RF-24).
    $props = inertiaProps($this->actingAs($this->requester)->get("/tickets/{$ticket->ticket_number}"));
    $history = $this->actingAs($this->requester)->getJson("/tickets/{$ticket->ticket_number}/sla-history")->assertForbidden();

    expect($props['sla'])->toBeNull()
        ->and($props['activePending'])->toBeNull()
        ->and(json_encode($props))->not->toContain('SEGREDO-MOTIVO')
        ->and($history->getContent())->not->toContain('SEGREDO-MOTIVO');
});

test('the SLA history summarizes targets, consumption and breaches, and lists the new events', function () {
    $ticket = openRealTicket();
    $ticket->forceFill(['status' => TicketStatus::InProgress, 'assigned_to' => $this->tech->id])->save();
    SlaPolicy::factory()->create(['priority' => 'low', 'first_response_minutes' => 60, 'resolution_minutes' => 720]);

    // Às 17:00 (9h consumidas, vencido): a troca registra a violação e abre a meta de 12h.
    $this->travelTo(Carbon::parse('2026-10-21 17:00:00'));
    $current = $ticket->fresh();
    app(UpdateTicketAttributes::class)($this->tech, $current, ['priority' => 'low'], $current->editVersion());

    $history = $this->actingAs($this->manager)->getJson("/tickets/{$ticket->ticket_number}/sla-history")->assertOk()->json();

    expect($history['sla'])->toMatchArray([
        'priority' => 'Baixa',
        'has_policy' => true,
        'resolution_minutes' => 720,
        'consumed_seconds' => 9 * 3600,
        'remaining_seconds' => 3 * 3600,
        'effective_seconds' => null,
    ])
        ->and($history['sla']['breaches']['resolution_at'])->toBe('2026-10-21T17:00:00+00:00')
        ->and($history['sla']['breaches']['first_response_at'])->toBe('2026-10-21T17:00:00+00:00');

    $kinds = collect($history['events'])->pluck('kind');
    expect($kinds)->toContain('sla_targets_changed')->toContain('sla_breached')->toContain('priority_changed');

    $targets = collect($history['events'])->firstWhere('kind', 'sla_targets_changed');

    expect($targets['resolution_minutes'])->toBe([480, 720])
        ->and($targets['first_response_minutes'])->toBe([30, 60])
        ->and($targets['consumed_seconds'])->toBe(9 * 3600)
        ->and($targets)->toHaveKey('consumed_seconds');
});

test('the SLA history tells when the ticket has no policy', function () {
    $this->policy->update(['is_active' => false]);
    $ticket = openRealTicket();

    $summary = $this->actingAs($this->manager)->getJson("/tickets/{$ticket->ticket_number}/sla-history")->assertOk()->json('sla');

    expect($summary)->toMatchArray(['priority' => 'Média', 'has_policy' => false, 'consumed_seconds' => null, 'remaining_seconds' => null]);
});

test('the listing, the SLA filter and the CSV use the deadlines recorded by the engine', function () {
    $late = openRealTicket('2026-10-21 08:00:00');
    // Prazo às 17:00: às 16:30 faltam 30 de 480 min, então já está em risco (>= 80% consumido).
    $atRisk = openRealTicket('2026-10-21 09:00:00');
    // Sem política ativa, o chamado abre sem prazo (D2).
    $this->policy->update(['is_active' => false]);
    $free = openRealTicket('2026-10-21 09:30:00');

    $this->travelTo(Carbon::parse('2026-10-21 16:30:00'));

    $rows = collect(inertiaProps($this->actingAs($this->manager)->get('/tickets'))['tickets']['data'])->pluck('sla', 'number');

    expect($rows[$late->ticket_number])->toBe('overdue')
        ->and($rows[$atRisk->ticket_number])->toBe('at_risk')
        ->and($rows[$free->ticket_number])->toBe('none');

    foreach (['overdue' => $late, 'at_risk' => $atRisk, 'none' => $free] as $state => $expected) {
        $numbers = collect(inertiaProps($this->actingAs($this->manager)->get("/tickets?sla={$state}"))['tickets']['data'])->pluck('number');

        expect($numbers->all())->toBe([$expected->ticket_number]);
    }

    // A exportação é restrita a administradores e superadministradores.
    $csv = $this->actingAs(User::factory()->withRole('admin')->create())->get('/tickets/export')->assertOk()->streamedContent();

    expect($csv)->toContain('Vencido')->toContain('Em risco')->toContain('Sem prazo definido');
});

test('the listing marks a ticket as met after it is resolved within the deadline', function () {
    $ticket = openRealTicket();
    $ticket->forceFill(['status' => TicketStatus::InProgress, 'assigned_to' => $this->tech->id])->save();

    $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
    app(ResolveTicket::class)($this->tech, $ticket->fresh(), TicketStatus::InProgress, 'Resolvido.');

    $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));
    $row = collect(inertiaProps($this->actingAs($this->manager)->get('/tickets'))['tickets']['data'])->firstWhere('number', $ticket->ticket_number);

    expect($row['sla'])->toBe('met');
});

test('the dashboard averages the first response and the effective resolution time', function () {
    // Primeira resposta em 10, 20 e 30 min: média de 20 min. Resolução efetiva de 1h e 3h: média de 2h.
    foreach ([600, 1200, 1800] as $seconds) {
        Ticket::factory()->create([
            'created_at' => '2026-10-21 08:00:00',
            'first_response_at' => Carbon::parse('2026-10-21 08:00:00')->addSeconds($seconds),
        ]);
    }
    Ticket::factory()->create(['status' => TicketStatus::Resolved, 'resolved_at' => now(), 'resolution_effective_seconds' => 3600]);
    Ticket::factory()->create(['status' => TicketStatus::Closed, 'resolved_at' => now(), 'resolution_effective_seconds' => 10800]);
    // Sem dado, ou ainda em andamento: fora da média.
    Ticket::factory()->create(['status' => TicketStatus::Resolved, 'resolved_at' => now(), 'resolution_effective_seconds' => null]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress, 'resolution_effective_seconds' => 999999]);

    $times = inertiaProps($this->actingAs($this->manager)->get('/dashboard'))['metrics']['times'];

    expect($times['first_response'])->toBe(['count' => 3, 'average_seconds' => 1200])
        ->and($times['resolution'])->toBe(['count' => 2, 'average_seconds' => 7200]);
});

test('the dashboard times are null when no ticket has the data', function () {
    Ticket::factory()->count(2)->create();

    $times = inertiaProps($this->actingAs($this->manager)->get('/dashboard'))['metrics']['times'];

    expect($times)->toBe([
        'first_response' => ['count' => 0, 'average_seconds' => null],
        'resolution' => ['count' => 0, 'average_seconds' => null],
    ]);
});

test('the dashboard times only consider tickets the user can access', function () {
    $mine = Ticket::factory()->create([
        'assigned_to' => $this->tech->id,
        'status' => TicketStatus::InProgress,
        'created_at' => '2026-10-21 08:00:00',
        'first_response_at' => '2026-10-21 08:10:00',
    ]);
    Ticket::factory()->create([
        'assigned_to' => User::factory()->withRole('technician')->create()->id,
        'status' => TicketStatus::InProgress,
        'created_at' => '2026-10-21 08:00:00',
        'first_response_at' => '2026-10-21 12:00:00',
    ]);

    $times = inertiaProps($this->actingAs($this->tech)->get('/dashboard'))['metrics']['times'];

    expect($mine->exists)->toBeTrue()
        ->and($times['first_response'])->toBe(['count' => 1, 'average_seconds' => 600]);
});
