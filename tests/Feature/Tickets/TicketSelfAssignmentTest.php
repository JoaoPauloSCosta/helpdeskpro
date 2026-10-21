<?php

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::Open,
        'assigned_to' => null,
        'title' => 'Impressora sem toner',
        'priority' => TicketPriority::High,
    ]);
});

function selfAssignUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}/self-assignment";
}

/* ---------- Acesso e permissão ---------- */

test('guests cannot self-assign', function () {
    $this->post(selfAssignUrl($this->ticket))->assertRedirect('/login');

    expect($this->ticket->fresh()->assigned_to)->toBeNull();
});

test('users without tickets.self_assign cannot take the ticket', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->post(selfAssignUrl($this->ticket))->assertForbidden();

    expect($this->ticket->fresh()->assigned_to)->toBeNull()
        ->and($this->ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and(TicketHistory::count())->toBe(0);
})->with(['requester', 'manager', 'admin']); // gestor e admin têm assign, não self_assign

test('inactive technicians are rejected', function () {
    $this->tech->forceFill(['is_active' => false])->save();

    $this->actingAs($this->tech)->post(selfAssignUrl($this->ticket))->assertForbidden();

    expect($this->ticket->fresh()->assigned_to)->toBeNull();
});

test('an unknown ticket returns not found', function () {
    $this->actingAs($this->tech)->post('/tickets/HD-ZZZZZZZZ/self-assignment')->assertNotFound();
});

/* ---------- Operação ---------- */

test('a technician takes an open ticket and it moves to in progress', function () {
    $original = $this->ticket->only(['ticket_number', 'title', 'description', 'requester_id', 'category_id', 'priority', 'department_id']);

    $this->actingAs($this->tech)
        ->post(selfAssignUrl($this->ticket))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number))
        ->assertSessionHas('success', 'Chamado assumido com sucesso.');

    $ticket = $this->ticket->fresh();

    expect($ticket->assigned_to)->toBe($this->tech->id)
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->only(array_keys($original)))->toEqual($original);
});

test('the technician always comes from the session', function () {
    $other = User::factory()->withRole('technician')->create();

    $this->actingAs($this->tech)->post(selfAssignUrl($this->ticket), [
        'assigned_to' => $other->id,
        'technician_id' => $other->id,
        'user_id' => $other->id,
        'status' => TicketStatus::Resolved->value,
    ])->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    expect($ticket->assigned_to)->toBe($this->tech->id)
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and(TicketHistory::sole()->user_id)->toBe($this->tech->id);
});

test('the audit event records the technician and the status transition', function () {
    $this->freezeSecond();

    $this->actingAs($this->tech)->post(selfAssignUrl($this->ticket));

    $event = TicketHistory::sole();

    expect($event->event_type)->toBe(TicketEvent::SelfAssigned)
        ->and($event->user_id)->toBe($this->tech->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue()
        ->and($event->old_values)->toBe(['assignee' => null, 'status' => 'open', 'label' => 'Aberto'])
        ->and($event->new_values)->toBe([
            'assignee' => ['id' => $this->tech->id, 'name' => 'Carlos Lima'],
            'status' => 'in_progress',
            'label' => 'Em atendimento',
        ]);
});

/* ---------- Elegibilidade do chamado ---------- */

test('an assigned ticket cannot be taken and the current technician is kept', function () {
    $winner = User::factory()->withRole('technician')->create();
    $this->ticket->forceFill(['assigned_to' => $winner->id])->save(); // ainda "Aberto", via atribuição manual

    $this->actingAs($this->tech)
        ->from(route('tickets.index'))
        ->post(selfAssignUrl($this->ticket))
        ->assertRedirect(route('tickets.index'))
        ->assertSessionHasErrors('self_assign');

    expect($this->ticket->fresh()->assigned_to)->toBe($winner->id)
        ->and($this->ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and(TicketHistory::count())->toBe(0);
});

test('only open tickets can be taken', function (TicketStatus $status) {
    $this->ticket->forceFill(['status' => $status])->save();

    $this->actingAs($this->tech)->post(selfAssignUrl($this->ticket))->assertSessionHasErrors('self_assign');

    expect($this->ticket->fresh()->assigned_to)->toBeNull()
        ->and($this->ticket->fresh()->status)->toBe($status);
})->with([
    TicketStatus::InProgress,
    TicketStatus::AwaitingRequester,
    TicketStatus::Pending,
    TicketStatus::PendingThirdParty,
    TicketStatus::PendingDepartments,
    TicketStatus::Resolved,
    TicketStatus::Closed,
]);

/* ---------- Concorrência ---------- */

test('the losing request of a race does not overwrite the winner', function () {
    $rival = User::factory()->withRole('technician')->create(['name' => 'Beatriz Ramos']);

    // As duas viram o chamado disponível; a rival grava primeiro.
    $this->actingAs($rival)->post(selfAssignUrl($this->ticket))->assertSessionHasNoErrors();
    $loser = $this->actingAs($this->tech)
        ->from(route('tickets.show', $this->ticket->ticket_number))
        ->post(selfAssignUrl($this->ticket));

    // O perdedor já não vê o chamado (atribuído a outra pessoa): volta para a listagem, sem sucesso.
    $loser->assertRedirect(route('tickets.index'))
        ->assertSessionHasErrors('self_assign')
        ->assertSessionMissing('success');

    $ticket = $this->ticket->fresh();
    expect($ticket->assigned_to)->toBe($rival->id)
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and(TicketHistory::count())->toBe(1)
        ->and(TicketHistory::sole()->user_id)->toBe($rival->id);
});

test('a failure while recording the audit leaves assignee and status untouched', function () {
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->tech)->post(selfAssignUrl($this->ticket)))
        ->toThrow(RuntimeException::class, 'falha simulada na auditoria');

    $ticket = $this->ticket->fresh();
    expect($ticket->assigned_to)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and(TicketHistory::count())->toBe(0);
});

/* ---------- Pontos de entrada ---------- */

test('the listing flags only the tickets the technician may take', function () {
    $assigned = Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_to' => $this->tech->id]);

    $rows = fn (User $user) => collect($this->actingAs($user)->get('/tickets')->viewData('page')['props']['tickets']['data'])
        ->mapWithKeys(fn ($row) => [$row['number'] => $row['can_self_assign']]);

    expect($rows($this->tech)->all())->toEqual([
        $this->ticket->ticket_number => true,
        $assigned->ticket_number => false,
    ]);

    expect($rows(User::factory()->withRole('manager')->create())->filter()->all())->toBe([]);
});

test('the details page offers self-assignment only while available', function () {
    $this->actingAs($this->tech)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page->where('can.selfAssign', true));

    $this->actingAs($this->tech)->post(selfAssignUrl($this->ticket));

    $this->actingAs($this->tech)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.selfAssign', false)
            ->where('ticket.assignee', 'Carlos Lima')
            ->where('ticket.status', 'Em atendimento'));
});

/* ---------- CHA-05 continua distinta ---------- */

test('manual assignment still keeps the status', function () {
    $manager = User::factory()->withRole('manager')->create();

    $this->actingAs($manager)->put("/tickets/{$this->ticket->ticket_number}/assignee", [
        'technician_id' => $this->tech->id,
        'expected_assignee_id' => null,
    ])->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and(TicketHistory::sole()->event_type)->toBe(TicketEvent::Assigned);
});
