<?php

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Gestora Paula']);
    $support = Department::factory()->create(['name' => 'Suporte N1']);
    $this->techA = User::factory()->withRole('technician')->create(['name' => 'Ana Técnica', 'department_id' => $support->id]);
    $this->techB = User::factory()->withRole('technician')->create(['name' => 'Bruno Técnico']);

    $this->ticket = Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_to' => null]);
});

function assigneeUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}/assignee";
}

function assignTicket(User $user, Ticket $ticket, ?User $technician, ?int $expected = null, bool $useCurrent = true)
{
    return test()->actingAs($user)->put(assigneeUrl($ticket), [
        'technician_id' => $technician?->id,
        'expected_assignee_id' => $useCurrent ? $ticket->fresh()->assigned_to : $expected,
    ]);
}

/** Papel avulso com exatamente as permissões indicadas. */
function userWithPermissions(array $slugs): User
{
    $role = Role::create(['name' => 'Papel de teste '.uniqid(), 'slug' => 'test-'.uniqid()]);
    $role->permissions()->attach(Permission::whereIn('slug', $slugs)->pluck('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests cannot assign', function () {
    $this->put(assigneeUrl($this->ticket), ['technician_id' => $this->techA->id])->assertRedirect('/login');
});

test('assigning an unassigned ticket requires tickets.assign', function () {
    $onlyReassign = userWithPermissions(['tickets.view_all_departments', 'tickets.reassign']);

    assignTicket($onlyReassign, $this->ticket, $this->techA)->assertForbidden();
    assignTicket($this->techB, $this->ticket, $this->techA)->assertForbidden(); // técnico não tem tickets.assign

    expect($this->ticket->fresh()->assigned_to)->toBeNull()
        ->and(TicketHistory::count())->toBe(0);
});

test('reassigning requires tickets.reassign', function () {
    $this->ticket->forceFill(['assigned_to' => $this->techA->id])->save();
    $onlyAssign = userWithPermissions(['tickets.view_all_departments', 'tickets.assign']);

    assignTicket($onlyAssign, $this->ticket, $this->techB)->assertForbidden();

    expect($this->ticket->fresh()->assigned_to)->toBe($this->techA->id);
});

test('users cannot assign tickets outside their scope', function () {
    $scoped = userWithPermissions(['tickets.assign', 'tickets.reassign']); // sem view_all_departments

    assignTicket($scoped, $this->ticket, $this->techA)->assertForbidden();
    $this->actingAs($scoped)->get("/tickets/{$this->ticket->ticket_number}")->assertForbidden();

    expect($this->ticket->fresh()->assigned_to)->toBeNull();
});

test('closed tickets cannot be assigned', function () {
    $this->ticket->forceFill(['status' => TicketStatus::Closed])->save();

    assignTicket($this->manager, $this->ticket, $this->techA)->assertForbidden();
});

/* ---------- Atribuição e reatribuição ---------- */

test('a manager assigns an eligible technician without changing the status', function () {
    assignTicket($this->manager, $this->ticket, $this->techA)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number))
        ->assertSessionHas('success', 'Chamado atribuído a Ana Técnica.');

    $ticket = $this->ticket->fresh();
    $event = TicketHistory::sole();

    expect($ticket->assigned_to)->toBe($this->techA->id)
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($event->event_type)->toBe(TicketEvent::Assigned)
        ->and($event->old_values)->toBeNull()
        ->and($event->new_values)->toBe(['id' => $this->techA->id, 'label' => 'Ana Técnica'])
        ->and($event->user_id)->toBe($this->manager->id);
});

test('reassigning keeps the status and records previous and new technician', function () {
    $this->ticket->forceFill(['assigned_to' => $this->techA->id, 'status' => TicketStatus::InProgress])->save();

    assignTicket($this->manager, $this->ticket, $this->techB)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Chamado reatribuído a Bruno Técnico.');

    $ticket = $this->ticket->fresh();
    $event = TicketHistory::sole();

    expect($ticket->assigned_to)->toBe($this->techB->id)
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($event->event_type)->toBe(TicketEvent::Reassigned)
        ->and($event->old_values)->toBe(['id' => $this->techA->id, 'label' => 'Ana Técnica'])
        ->and($event->new_values)->toBe(['id' => $this->techB->id, 'label' => 'Bruno Técnico']);
});

test('author and time of the event come from the server', function () {
    $this->freezeSecond();
    $intruder = User::factory()->create();

    $this->actingAs($this->manager)->put(assigneeUrl($this->ticket), [
        'technician_id' => $this->techA->id,
        'expected_assignee_id' => null,
        'user_id' => $intruder->id,
        'created_at' => '2000-01-01 00:00:00',
        'status' => TicketStatus::Resolved->value,
        'requester_id' => $intruder->id,
        'department_id' => Department::factory()->create()->id,
    ])->assertSessionHasNoErrors();

    $event = TicketHistory::sole();
    $ticket = $this->ticket->fresh();

    expect($event->user_id)->toBe($this->manager->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue()
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->requester_id)->toBe($this->ticket->requester_id)
        ->and($ticket->department_id)->toBe($this->ticket->department_id);
});

/* ---------- Elegibilidade ---------- */

test('ineligible technicians are rejected', function (Closure $makeUser) {
    assignTicket($this->manager, $this->ticket, $makeUser())->assertSessionHasErrors('technician_id');

    expect($this->ticket->fresh()->assigned_to)->toBeNull()
        ->and(TicketHistory::count())->toBe(0);
})->with([
    'técnico inativo' => fn () => User::factory()->withRole('technician')->inactive()->create(),
    'gestor' => fn () => User::factory()->withRole('manager')->create(),
    'solicitante' => fn () => User::factory()->withRole('requester')->create(),
    'sem papel' => fn () => User::factory()->create(),
]);

test('unknown technicians are rejected', function () {
    $this->actingAs($this->manager)
        ->put(assigneeUrl($this->ticket), ['technician_id' => 999999, 'expected_assignee_id' => null])
        ->assertSessionHasErrors('technician_id');
});

test('reassigning to the current technician is rejected', function () {
    $this->ticket->forceFill(['assigned_to' => $this->techA->id])->save();

    assignTicket($this->manager, $this->ticket, $this->techA)->assertSessionHasErrors('technician_id');

    expect(TicketHistory::count())->toBe(0);
});

/* ---------- Concorrência e integridade ---------- */

test('a stale request does not overwrite a more recent assignment', function () {
    // O gestor abriu o modal com o chamado sem responsável; outra pessoa atribuiu antes dele salvar.
    $this->ticket->forceFill(['assigned_to' => $this->techA->id])->save();

    assignTicket($this->manager, $this->ticket, $this->techB, expected: null, useCurrent: false)
        ->assertSessionHasErrors('conflict');

    expect($this->ticket->fresh()->assigned_to)->toBe($this->techA->id)
        ->and(TicketHistory::count())->toBe(0);
});

test('a conflict for a user who may only assign is reported as a conflict, not access denied', function () {
    $onlyAssign = userWithPermissions(['tickets.view_all_departments', 'tickets.assign']);
    $this->ticket->forceFill(['assigned_to' => $this->techA->id])->save();

    assignTicket($onlyAssign, $this->ticket, $this->techB, expected: null, useCurrent: false)
        ->assertSessionHasErrors('conflict');
});

test('a failure while recording the audit leaves the ticket unassigned', function () {
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));

    $this->withoutExceptionHandling();

    expect(fn () => assignTicket($this->manager, $this->ticket, $this->techA))
        ->toThrow(RuntimeException::class, 'falha simulada na auditoria');

    expect($this->ticket->fresh()->assigned_to)->toBeNull()
        ->and(TicketHistory::count())->toBe(0);
});

/* ---------- Integração com a CHA-03 ---------- */

test('the details page exposes the right action and loads technicians on demand', function () {
    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.assign', true)
            ->where('can.reassign', false)
            ->where('assignment', ['current' => null])
            ->missing('assignableTechnicians')
            // Recarga parcial feita pelo modal.
            ->reloadOnly('assignableTechnicians', fn (Assert $reload) => $reload
                ->where('assignableTechnicians', [
                    ['id' => $this->techA->id, 'name' => 'Ana Técnica', 'department' => 'Suporte N1'],
                    ['id' => $this->techB->id, 'name' => 'Bruno Técnico', 'department' => null],
                ])));
});

test('the current technician is excluded from the reassignment list', function () {
    $this->ticket->forceFill(['assigned_to' => $this->techA->id])->save();

    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.reassign', true)
            ->where('can.assign', false)
            ->where('assignment.current', ['id' => $this->techA->id, 'name' => 'Ana Técnica', 'department' => 'Suporte N1'])
            ->reloadOnly('assignableTechnicians', fn (Assert $reload) => $reload
                ->where('assignableTechnicians', [
                    ['id' => $this->techB->id, 'name' => 'Bruno Técnico', 'department' => null],
                ])));
});

test('requesters and technicians do not receive the technician list', function () {
    $requester = User::factory()->withRole('requester')->create();
    $this->ticket->forceFill(['requester_id' => $requester->id])->save();

    foreach ([$requester, $this->techA] as $user) {
        $this->actingAs($user)
            ->get("/tickets/{$this->ticket->ticket_number}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.assign', false)
                ->where('can.reassign', false)
                ->where('assignment', null)
                ->reloadOnly('assignableTechnicians', fn (Assert $reload) => $reload
                    ->where('assignableTechnicians', [])));
    }
});
