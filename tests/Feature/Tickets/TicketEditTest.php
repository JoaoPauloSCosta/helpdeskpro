<?php

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create();
    $this->requester = User::factory()->withRole('requester')->create();

    $this->department = Department::factory()->create(['name' => 'Financeiro']);
    $this->category = TicketCategory::factory()->create(['name' => 'Hardware']);
    $this->otherCategory = TicketCategory::factory()->create(['name' => 'Rede']);

    $this->ticket = Ticket::factory()->create([
        'requester_id' => $this->requester->id,
        'assigned_to' => $this->tech->id,
        'department_id' => $this->department->id,
        'category_id' => $this->category->id,
        'priority' => TicketPriority::Medium,
        'status' => TicketStatus::InProgress,
    ]);
});

function editUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}";
}

/** Envio de edição com a versão atual do chamado. */
function patchTicket(User $user, Ticket $ticket, array $data)
{
    return test()->actingAs($user)->patch(editUrl($ticket), [
        'version' => $ticket->fresh()->editVersion(),
        ...$data,
    ]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get(editUrl($this->ticket).'/edit')->assertRedirect('/login');
    $this->patch(editUrl($this->ticket), ['priority' => 'high'])->assertRedirect('/login');
});

test('requesters cannot open or submit the operational edit', function () {
    $this->actingAs($this->requester)->get(editUrl($this->ticket).'/edit')->assertForbidden();
    patchTicket($this->requester, $this->ticket, ['priority' => 'high'])->assertForbidden();

    expect($this->ticket->fresh()->priority)->toBe(TicketPriority::Medium)
        ->and(TicketHistory::count())->toBe(0);
});

test('administrators have no operational edit by default', function () {
    $admin = User::factory()->withRole('admin')->create();

    $this->actingAs($admin)->get(editUrl($this->ticket).'/edit')->assertForbidden();
    $this->actingAs($admin)
        ->get(editUrl($this->ticket))
        ->assertInertia(fn (Assert $page) => $page->where('can.update', false));
});

test('users outside the scope cannot edit, even with the permissions', function () {
    $otherTech = User::factory()->withRole('technician')->create();

    $this->actingAs($otherTech)->get(editUrl($this->ticket).'/edit')->assertForbidden();
    patchTicket($otherTech, $this->ticket, ['priority' => 'high'])->assertForbidden();

    expect($this->ticket->fresh()->priority)->toBe(TicketPriority::Medium);
});

test('closed tickets cannot be edited', function () {
    $this->ticket->forceFill(['status' => TicketStatus::Closed])->save();

    $this->actingAs($this->manager)->get(editUrl($this->ticket).'/edit')->assertForbidden();
});

test('an unknown identifier returns not found', function () {
    $this->actingAs($this->manager)->get('/tickets/HD-ZZZZZZZZ/edit')->assertNotFound();
});

/* ---------- Página ---------- */

test('the edit page only offers the attributes the technician may change', function () {
    $this->category->update(['is_active' => false]);
    TicketCategory::factory()->create(['name' => 'Antiga', 'is_active' => false]);

    $this->actingAs($this->tech)
        ->get(editUrl($this->ticket).'/edit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tickets/Edit')
            ->where('ticket.number', $this->ticket->ticket_number)
            ->where('values', [
                'priority' => 'medium',
                'category_id' => (string) $this->category->id,
                'department_id' => (string) $this->department->id,
            ])
            ->where('can', ['priority' => true, 'category' => true, 'department' => false])
            ->has('options.priorities', count(TicketPriority::cases()))
            // A categoria atual inativa continua listada; outras inativas não.
            ->where('options.categories', [
                ['value' => (string) $this->category->id, 'label' => 'Hardware (inativa)'],
                ['value' => (string) $this->otherCategory->id, 'label' => 'Rede'],
            ])
            ->where('options.departments', [])
            ->where('current.department', 'Financeiro')
            ->where('version', $this->ticket->editVersion()));
});

/* ---------- Edição ---------- */

test('a technician changes priority and category with one audit event each', function () {
    patchTicket($this->tech, $this->ticket, [
        'priority' => 'high',
        'category_id' => $this->otherCategory->id,
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number))
        ->assertSessionHas('success', 'Alterações salvas.');

    $ticket = $this->ticket->fresh();
    expect($ticket->priority)->toBe(TicketPriority::High)
        ->and($ticket->category_id)->toBe($this->otherCategory->id);

    $events = TicketHistory::orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->event_type)->toBe(TicketEvent::PriorityChanged)
        ->and($events[0]->old_values)->toBe(['priority' => 'medium', 'label' => 'Média'])
        ->and($events[0]->new_values)->toBe(['priority' => 'high', 'label' => 'Alta'])
        ->and($events[0]->user_id)->toBe($this->tech->id)
        ->and($events[0]->created_at)->not->toBeNull()
        ->and($events[1]->event_type)->toBe(TicketEvent::CategoryChanged)
        ->and($events[1]->old_values)->toBe(['id' => $this->category->id, 'label' => 'Hardware'])
        ->and($events[1]->new_values)->toBe(['id' => $this->otherCategory->id, 'label' => 'Rede']);
});

test('a technician without the priority permission is blocked', function () {
    $role = Role::create(['name' => 'Técnico restrito', 'slug' => 'restricted-tech']);
    $role->permissions()->attach(
        Permission::whereIn('slug', ['tickets.change_category'])->pluck('id'),
        ['created_at' => now()],
    );
    $restricted = User::factory()->create(['role_id' => $role->id]);
    $this->ticket->forceFill(['assigned_to' => $restricted->id])->save();

    patchTicket($restricted, $this->ticket, ['priority' => 'high'])->assertForbidden();
    patchTicket($restricted, $this->ticket, ['category_id' => $this->otherCategory->id])->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->priority)->toBe(TicketPriority::Medium)
        ->and($this->ticket->fresh()->category_id)->toBe($this->otherCategory->id);
});

test('technicians cannot transfer departments', function () {
    $target = Department::factory()->create();

    patchTicket($this->tech, $this->ticket, ['department_id' => $target->id])->assertForbidden();

    expect($this->ticket->fresh()->department_id)->toBe($this->department->id)
        ->and(TicketHistory::count())->toBe(0);
});

test('a manager transfers the department and the previous one is kept in the history', function () {
    $target = Department::factory()->create(['name' => 'Logística']);

    patchTicket($this->manager, $this->ticket, ['department_id' => $target->id])->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();
    $event = TicketHistory::sole();

    expect($ticket->department_id)->toBe($target->id)
        ->and($ticket->requester_id)->toBe($this->requester->id)
        ->and($event->event_type)->toBe(TicketEvent::DepartmentTransferred)
        ->and($event->old_values)->toBe(['id' => $this->department->id, 'label' => 'Financeiro'])
        ->and($event->new_values)->toBe(['id' => $target->id, 'label' => 'Logística']);
});

test('fields outside the contract are ignored', function () {
    $number = $this->ticket->ticket_number;
    $createdAt = $this->ticket->created_at->toIso8601String();

    patchTicket($this->manager, $this->ticket, [
        'priority' => 'low',
        'ticket_number' => 'HD-AAAAAAAA',
        'requester_id' => $this->manager->id,
        'assigned_to' => $this->manager->id,
        'status' => TicketStatus::Closed->value,
        'title' => 'Outro título',
        'description' => 'Outra descrição',
        'created_at' => '2000-01-01 00:00:00',
        'resolution_due_at' => '2030-01-01 00:00:00',
    ])->assertSessionHasNoErrors();

    $ticket = Ticket::firstWhere('id', $this->ticket->id);

    expect($ticket->ticket_number)->toBe($number)
        ->and($ticket->priority)->toBe(TicketPriority::Low)
        ->and($ticket->requester_id)->toBe($this->requester->id)
        ->and($ticket->assigned_to)->toBe($this->tech->id)
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->title)->toBe($this->ticket->title)
        ->and($ticket->description)->toBe($this->ticket->description)
        ->and($ticket->created_at->toIso8601String())->toBe($createdAt)
        ->and($ticket->resolution_due_at)->toBeNull();
});

/* ---------- Validação ---------- */

test('invalid values are rejected without partial changes', function (Closure $payload, string $field) {
    patchTicket($this->manager, $this->ticket, ['priority' => 'high', ...$payload()])->assertSessionHasErrors($field);

    $ticket = $this->ticket->fresh();
    expect($ticket->priority)->toBe(TicketPriority::Medium)
        ->and($ticket->category_id)->toBe($this->category->id)
        ->and($ticket->department_id)->toBe($this->department->id)
        ->and(TicketHistory::count())->toBe(0);
})->with([
    'prioridade inválida' => [fn () => ['priority' => 'urgentissima'], 'priority'],
    'categoria inexistente' => [fn () => ['category_id' => 999999], 'category_id'],
    'categoria inativa' => [fn () => ['category_id' => TicketCategory::factory()->create(['is_active' => false])->id], 'category_id'],
    'departamento inexistente' => [fn () => ['department_id' => 999999], 'department_id'],
    'departamento inativo' => [fn () => ['department_id' => Department::factory()->create(['is_active' => false])->id], 'department_id'],
    'sem versão' => [fn () => ['version' => ''], 'version'],
]);

test('keeping an inactive current category is accepted', function () {
    $this->category->update(['is_active' => false]);

    patchTicket($this->tech, $this->ticket, ['priority' => 'high', 'category_id' => $this->category->id])
        ->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->priority)->toBe(TicketPriority::High)
        ->and(TicketHistory::sole()->event_type)->toBe(TicketEvent::PriorityChanged);
});

test('saving without changes writes nothing', function () {
    $updatedAt = $this->ticket->updated_at->toIso8601String();
    $this->travel(5)->minutes();

    patchTicket($this->tech, $this->ticket, ['priority' => 'medium', 'category_id' => $this->category->id])
        ->assertRedirect(route('tickets.edit', $this->ticket->ticket_number))
        ->assertSessionHas('info', 'Nenhuma alteração para salvar.');

    expect(TicketHistory::count())->toBe(0)
        ->and($this->ticket->fresh()->updated_at->toIso8601String())->toBe($updatedAt);
});

/* ---------- Concorrência ---------- */

test('a concurrent change is not overwritten', function () {
    $staleVersion = $this->ticket->editVersion();

    // Outra pessoa altera a prioridade depois que o formulário foi aberto.
    $this->ticket->forceFill(['priority' => TicketPriority::Critical])->save();

    $this->actingAs($this->tech)
        ->patch(editUrl($this->ticket), ['category_id' => $this->otherCategory->id, 'version' => $staleVersion])
        ->assertSessionHasErrors('conflict');

    expect($this->ticket->fresh()->category_id)->toBe($this->category->id)
        ->and($this->ticket->fresh()->priority)->toBe(TicketPriority::Critical);
});

/* ---------- SLA ---------- */

test('changing the priority of a ticket without an applied policy keeps deadlines and first response and records the overdue violations', function () {
    $deadlines = [
        'first_response_due_at' => now()->subHours(3)->startOfSecond(),
        'first_response_at' => now()->subHours(2)->startOfSecond(),
        'resolution_due_at' => now()->subHour()->startOfSecond(),
    ];
    $this->ticket->forceFill($deadlines)->save();

    patchTicket($this->tech, $this->ticket, ['priority' => 'low'])->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    // Sem política na nova prioridade nem meta aplicada, nada é recalculado (D2): os instantes gravados
    // se mantêm e as violações já vencidas ficam registradas (RF-16.3).
    foreach ($deadlines as $column => $value) {
        expect($ticket->{$column}->equalTo($value))->toBeTrue();
    }
    expect($ticket->slaState()->value)->toBe('overdue')
        ->and($ticket->firstResponseSlaState()->value)->toBe('overdue')
        ->and($ticket->first_response_breached_at)->not->toBeNull()
        ->and($ticket->resolution_breached_at)->not->toBeNull()
        ->and(TicketHistory::where('event_type', TicketEvent::PriorityChanged)->sole()->old_values['priority'])->toBe('medium')
        ->and(TicketHistory::where('event_type', TicketEvent::SlaTargetsChanged)->count())->toBe(0);
});

/* ---------- Histórico nos detalhes ---------- */

test('the history is shown only to users with audit access', function () {
    patchTicket($this->manager, $this->ticket, ['priority' => 'critical'])->assertSessionHasNoErrors();

    $events = $this->actingAs($this->manager)
        ->getJson(editUrl($this->ticket).'/audit')
        ->assertOk()
        ->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['title'])->toBe('Prioridade alterada')
        ->and($events[0]['changes'])->toBe([['field' => 'Prioridade', 'from' => 'Média', 'to' => 'Crítica']])
        ->and($events[0]['author'])->toBe($this->manager->name);

    $this->actingAs($this->requester)
        ->get(editUrl($this->ticket))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewAudit', false));
    $this->actingAs($this->requester)->getJson(editUrl($this->ticket).'/audit')->assertForbidden();

    // O técnico responsável tem `audit.view` por padrão (RF-19, v1.8).
    $this->actingAs($this->tech)
        ->get(editUrl($this->ticket))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewAudit', true));
});
