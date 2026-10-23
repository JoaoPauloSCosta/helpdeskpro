<?php

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Marina Souza']);
    $this->requester = User::factory()->withRole('requester')->create();

    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::AwaitingRequester,
        'assigned_to' => $this->tech->id,
        'requester_id' => $this->requester->id,
        'title' => 'Impressora sem toner',
        'priority' => TicketPriority::High,
    ]);
});

function statusUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}/status";
}

/** Envia a alteração; sem `$expected`, usa o status gravado agora (o que a tela mostraria). */
function putStatus(User $user, Ticket $ticket, mixed $status, ?string $expected = null, array $extra = [])
{
    return test()->actingAs($user)->put(statusUrl($ticket), [
        'status' => $status,
        'expected_status' => $expected ?? $ticket->fresh()->status->value,
        ...$extra,
    ]);
}

/** Valores técnicos dos destinos que a tela de detalhes oferece ao usuário. */
function offeredTargets(User $user, Ticket $ticket): ?array
{
    $statusChange = test()->actingAs($user)->get("/tickets/{$ticket->ticket_number}")->viewData('page')['props']['statusChange'];

    return $statusChange === null ? null : array_column($statusChange['targets'], 'value');
}

/* ---------- Acesso e permissão ---------- */

test('guests cannot change the status', function () {
    $this->put(statusUrl($this->ticket), ['status' => 'in_progress', 'expected_status' => 'awaiting_requester'])
        ->assertRedirect('/login');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::AwaitingRequester);
});

test('users without tickets.change_status cannot change the status', function (string $role) {
    $user = $role === 'requester' ? $this->requester : User::factory()->withRole($role)->create();

    putStatus($user, $this->ticket, 'in_progress')->assertForbidden();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::AwaitingRequester)
        ->and(TicketHistory::count())->toBe(0);
})->with([
    'solicitante dono do chamado' => 'requester',
    'administrador com visibilidade global' => 'admin', // RN-03: ver não é alterar
]);

test('technicians cannot change tickets outside their access', function () {
    $other = User::factory()->withRole('technician')->create();

    putStatus($other, $this->ticket, 'in_progress')->assertForbidden();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::AwaitingRequester)
        ->and(TicketHistory::count())->toBe(0);
});

test('inactive users are rejected', function () {
    $this->tech->forceFill(['is_active' => false])->save();

    putStatus($this->tech, $this->ticket, 'in_progress')->assertForbidden();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::AwaitingRequester);
});

test('an unknown ticket returns not found', function () {
    $this->actingAs($this->tech)
        ->put('/tickets/HD-ZZZZZZZZ/status', ['status' => 'in_progress', 'expected_status' => 'pending'])
        ->assertNotFound();
});

/* ---------- Transições permitidas ---------- */

test('the assigned technician resumes a paused ticket', function (TicketStatus $paused) {
    $this->ticket->forceFill(['status' => $paused])->save();

    putStatus($this->tech, $this->ticket, 'in_progress')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number))
        ->assertSessionHas('success', 'Status alterado para Em atendimento.');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress);
})->with(fn () => TicketStatus::paused());

test('a manager with global visibility may change the status', function () {
    putStatus($this->manager, $this->ticket, 'in_progress')->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and(TicketHistory::sole()->user_id)->toBe($this->manager->id);
});

test('an open ticket with an assignee can be put in progress, keeping the assignee', function () {
    $this->ticket->forceFill(['status' => TicketStatus::Open])->save();

    putStatus($this->tech, $this->ticket, 'in_progress')->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($this->ticket->fresh()->assigned_to)->toBe($this->tech->id);
});

test('an open ticket without an assignee cannot be put in progress by a status change', function () {
    $this->ticket->forceFill(['status' => TicketStatus::Open, 'assigned_to' => null])->save();

    putStatus($this->manager, $this->ticket, 'in_progress')->assertSessionHasErrors('status');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and(TicketHistory::count())->toBe(0);
});

/* ---------- Transições rejeitadas ---------- */

test('transitions outside this flow or outside RF-10 are rejected', function (TicketStatus $from, TicketStatus $to) {
    $this->ticket->forceFill(['status' => $from])->save();

    putStatus($this->manager, $this->ticket, $to->value)->assertSessionHasErrors('status');

    expect($this->ticket->fresh()->status)->toBe($from)
        ->and(TicketHistory::count())->toBe(0);
})->with([
    // Previstas no RF-10, mas executadas por fluxos próprios (CHA-08 a CHA-11).
    'pendência (CHA-08)' => [TicketStatus::InProgress, TicketStatus::AwaitingRequester],
    'pendência terceiros (CHA-08)' => [TicketStatus::InProgress, TicketStatus::PendingThirdParty],
    'resolução (CHA-09)' => [TicketStatus::InProgress, TicketStatus::Resolved],
    'reabertura (CHA-10)' => [TicketStatus::Resolved, TicketStatus::Open],
    'encerramento (CHA-11)' => [TicketStatus::Resolved, TicketStatus::Closed],
    // Não previstas no RF-10.
    'aberto para encerrado' => [TicketStatus::Open, TicketStatus::Closed],
    'aberto para resolvido' => [TicketStatus::Open, TicketStatus::Resolved],
    'entre pausas' => [TicketStatus::Pending, TicketStatus::PendingDepartments],
    'pausa para resolvido' => [TicketStatus::AwaitingRequester, TicketStatus::Resolved],
    'encerrado para em atendimento' => [TicketStatus::Closed, TicketStatus::InProgress],
    'encerrado para aberto' => [TicketStatus::Closed, TicketStatus::Open],
    'em atendimento para aberto' => [TicketStatus::InProgress, TicketStatus::Open],
]);

test('the current status is not accepted as a new status', function () {
    putStatus($this->tech, $this->ticket, 'awaiting_requester')
        ->assertSessionHasErrors(['status' => 'Selecione um status diferente do atual.']);

    expect(TicketHistory::count())->toBe(0);
});

test('unknown or malformed status values are rejected', function (mixed $status) {
    putStatus($this->tech, $this->ticket, $status)->assertSessionHasErrors('status');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::AwaitingRequester)
        ->and(TicketHistory::count())->toBe(0);
})->with([
    'inexistente' => 'archived',
    'rótulo em vez do valor' => 'Em atendimento',
    'vazio' => '',
    'nulo' => null,
    'lista' => [['in_progress']],
    'número' => 2,
]);

test('the expected status is required and must be a known status', function (mixed $expected) {
    $this->actingAs($this->tech)
        ->put(statusUrl($this->ticket), ['status' => 'in_progress', 'expected_status' => $expected])
        ->assertSessionHasErrors('expected_status');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::AwaitingRequester);
})->with([null, 'waiting']);

/* ---------- Auditoria e integridade ---------- */

test('the history records previous and new status, author and server time', function () {
    $this->freezeSecond();

    putStatus($this->tech, $this->ticket, 'in_progress')->assertSessionHasNoErrors();

    $event = TicketHistory::sole();

    expect($event->event_type)->toBe(TicketEvent::StatusChanged)
        ->and($event->ticket_id)->toBe($this->ticket->id)
        ->and($event->user_id)->toBe($this->tech->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue()
        ->and($event->old_values)->toBe(['status' => 'awaiting_requester', 'label' => 'Aguardando solicitante'])
        ->and($event->new_values)->toBe(['status' => 'in_progress', 'label' => 'Em atendimento']);
});

test('author and time always come from the server', function () {
    $this->freezeSecond();

    putStatus($this->tech, $this->ticket, 'in_progress', extra: [
        'user_id' => $this->manager->id,
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ])->assertSessionHasNoErrors();

    $event = TicketHistory::sole();

    expect($event->user_id)->toBe($this->tech->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue()
        ->and($this->ticket->fresh()->updated_at->equalTo(now()))->toBeTrue();
});

test('changing the status does not touch any other field', function () {
    $other = User::factory()->withRole('technician')->create();
    $before = $this->ticket->fresh()->getAttributes();

    putStatus($this->tech, $this->ticket, 'in_progress', extra: [
        'assigned_to' => $other->id,
        'requester_id' => $other->id,
        'department_id' => 999,
        'category_id' => 999,
        'priority' => 'low',
        'title' => 'Título manipulado',
        'description' => 'Descrição manipulada',
        'ticket_number' => 'HD-AAAAAAAA',
        'resolution_summary' => 'Resolvido',
        'resolved_at' => now()->toDateTimeString(),
        'closed_at' => now()->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $after = $this->ticket->fresh()->getAttributes();

    expect(Arr::except($after, ['status', 'updated_at']))->toEqual(Arr::except($before, ['status', 'updated_at']))
        ->and($after['status'])->toBe('in_progress');
});

test('a status changed by someone else is reported as a conflict and not overwritten', function () {
    // A tela foi aberta com "Aguardando solicitante"; outra pessoa retomou e a pendência voltou.
    $this->ticket->forceFill(['status' => TicketStatus::Pending])->save();

    putStatus($this->tech, $this->ticket, 'in_progress', expected: 'awaiting_requester')
        ->assertSessionHasErrors('conflict')
        ->assertSessionMissing('success');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Pending)
        ->and(TicketHistory::count())->toBe(0);
});

test('a repeated or concurrent submission of the same transition is applied only once', function () {
    putStatus($this->tech, $this->ticket, 'in_progress', expected: 'awaiting_requester')->assertSessionHasNoErrors();
    putStatus($this->manager, $this->ticket, 'in_progress', expected: 'awaiting_requester')->assertSessionHasErrors('conflict');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and(TicketHistory::count())->toBe(1)
        ->and(TicketHistory::sole()->user_id)->toBe($this->tech->id);
});

test('a failure while recording the audit leaves the status untouched', function () {
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));
    $this->withoutExceptionHandling();

    expect(fn () => putStatus($this->tech, $this->ticket, 'in_progress'))
        ->toThrow(RuntimeException::class, 'falha simulada na auditoria');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::AwaitingRequester)
        ->and(TicketHistory::count())->toBe(0);
});

/* ---------- SLA ---------- */

test('resuming a ticket paused without a recorded pause keeps SLA deadlines and does not count as first response', function () {
    $deadlines = [
        'first_response_due_at' => now()->subHours(3)->startOfSecond(),
        'resolution_due_at' => now()->subHour()->startOfSecond(),
    ];
    $this->ticket->forceFill($deadlines)->save();

    expect($this->ticket->fresh()->slaState()->value)->toBe('paused');

    putStatus($this->tech, $this->ticket, 'in_progress')->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    // Sem pausa registrada (anterior ao CHA-08) não há duração a somar: os prazos gravados se mantêm.
    foreach ($deadlines as $column => $value) {
        expect($ticket->{$column}->equalTo($value))->toBeTrue();
    }
    expect($ticket->first_response_at)->toBeNull() // RF-15.1: mudança de status não é primeira resposta
        ->and($ticket->resolved_at)->toBeNull()
        ->and($ticket->slaState()->value)->toBe('overdue')
        ->and($ticket->firstResponseSlaState()->value)->toBe('overdue');
});

/* ---------- Tela de detalhes ---------- */

test('read-only users receive neither the action nor the options', function (string $role) {
    $user = $role === 'requester' ? $this->requester : User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.changeStatus', false)
            ->where('statusChange', null));
})->with(['requester', 'admin']);

test('the details page offers exactly the transitions the backend accepts', function (TicketStatus $status, array $expected) {
    $this->ticket->forceFill(['status' => $status])->save();

    expect(offeredTargets($this->manager, $this->ticket))->toBe($expected);

    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.changeStatus', $expected !== [])
            ->where('statusChange.current', $status->value));

    // Cada destino oferecido é aceito pelo backend.
    foreach ($expected as $target) {
        $this->ticket->forceFill(['status' => $status])->save();
        putStatus($this->manager, $this->ticket, $target)->assertSessionHasNoErrors();
        expect($this->ticket->fresh()->status->value)->toBe($target);
    }
})->with([
    'aberto com responsável' => [TicketStatus::Open, ['in_progress']],
    'em atendimento' => [TicketStatus::InProgress, []],
    'aguardando solicitante' => [TicketStatus::AwaitingRequester, ['in_progress']],
    'pendente' => [TicketStatus::Pending, ['in_progress']],
    'pendente terceiros' => [TicketStatus::PendingThirdParty, ['in_progress']],
    'pendente departamentos' => [TicketStatus::PendingDepartments, ['in_progress']],
    'resolvido' => [TicketStatus::Resolved, []],
    'encerrado' => [TicketStatus::Closed, []],
]);

test('an open ticket without an assignee offers no status change', function () {
    $this->ticket->forceFill(['status' => TicketStatus::Open, 'assigned_to' => null])->save();

    expect(offeredTargets($this->manager, $this->ticket))->toBe([]);
});

test('the change appears in the details, the history and the listing', function () {
    putStatus($this->tech, $this->ticket, 'in_progress')->assertSessionHasNoErrors();

    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.status', 'Em atendimento')
            ->where('statusChange.current', 'in_progress'));

    $events = $this->actingAs($this->manager)
        ->getJson("/tickets/{$this->ticket->ticket_number}/audit")
        ->assertOk()
        ->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['title'])->toBe('Status alterado')
        ->and($events[0]['changes'])->toBe([['field' => 'Status', 'from' => 'Aguardando solicitante', 'to' => 'Em atendimento']])
        ->and($events[0]['author'])->toBe('Carlos Lima');

    $row = collect($this->actingAs($this->tech)->get('/tickets')->viewData('page')['props']['tickets']['data'])
        ->firstWhere('number', $this->ticket->ticket_number);

    expect($row['status'])->toBe('Em atendimento');
});
