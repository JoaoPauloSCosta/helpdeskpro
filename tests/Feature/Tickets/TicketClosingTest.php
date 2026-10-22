<?php

use App\Actions\Tickets\CloseTicket;
use App\Actions\Tickets\ResolveTicket;
use App\Actions\Tickets\TicketStatusConflict;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

const CLOSE_SOLUTION = "Toner substituído e cabeçote limpo.\nImpressão de teste aprovada.";

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Marina Souza']);
    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza']);

    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'assigned_to' => $this->tech->id,
        'requester_id' => $this->requester->id,
        'title' => 'Impressora sem toner',
        'priority' => TicketPriority::High,
    ]);

    // Resolvido pelo fluxo real (CHA-09), com o evento de auditoria correspondente.
    app(ResolveTicket::class)($this->tech, $this->ticket, TicketStatus::InProgress, CLOSE_SOLUTION);
    $this->resolvedAt = $this->ticket->fresh()->resolved_at;
});

function closingUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}/closing";
}

/** Envia o encerramento; sem `$expected`, usa o status gravado agora (o que a tela mostraria). */
function postClosing(User $user, Ticket $ticket, array $data = [], ?string $expected = null)
{
    return test()->actingAs($user)->post(closingUrl($ticket), [
        'expected_status' => $expected ?? $ticket->fresh()->status->value,
        ...$data,
    ]);
}

function closedEvents()
{
    return TicketHistory::where('event_type', TicketEvent::Closed)->orderBy('id')->get();
}

/** Nada foi gravado: status, `closed_at`, solução e auditoria intactos. */
function expectNotClosed(Ticket $ticket, TicketStatus $status = TicketStatus::Resolved): void
{
    $fresh = $ticket->fresh();

    expect($fresh->status)->toBe($status)
        ->and($fresh->closed_at)->toBeNull()
        ->and($fresh->resolution_summary)->toBe(CLOSE_SOLUTION)
        ->and(closedEvents())->toHaveCount(0)
        // Somente o evento da resolução feita no preparo do teste.
        ->and(TicketHistory::count())->toBe(1);
}

/** Papel avulso com exatamente as permissões indicadas. */
function closerWithPermissions(array $slugs): User
{
    $role = Role::create(['name' => 'Papel de teste '.uniqid(), 'slug' => 'test-'.uniqid()]);
    $role->permissions()->attach(Permission::whereIn('slug', $slugs)->pluck('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso e permissão ---------- */

test('guests cannot close a ticket', function () {
    $this->post(closingUrl($this->ticket), ['expected_status' => 'resolved'])->assertRedirect('/login');

    expectNotClosed($this->ticket);
});

test('users without tickets.close cannot close, whatever their role or relation to the ticket', function (string $role) {
    $user = match ($role) {
        'technician' => $this->tech, // responsável e autor da resolução, mas sem `tickets.close`
        'requester' => $this->requester, // solicitante original (RF-14, regra 5)
        default => User::factory()->withRole($role)->create(), // administrador: visibilidade global
    };

    postClosing($user, $this->ticket)->assertForbidden();

    expectNotClosed($this->ticket);
})->with([
    'técnico responsável' => 'technician',
    'solicitante original' => 'requester',
    'administrador' => 'admin',
]);

test('a user with tickets.close but without access to the ticket cannot close it', function () {
    $outsider = closerWithPermissions(['tickets.close']);

    postClosing($outsider, $this->ticket)->assertForbidden();

    expectNotClosed($this->ticket);
});

test('an inactive manager is rejected', function () {
    $this->manager->forceFill(['is_active' => false])->save();

    postClosing($this->manager, $this->ticket)->assertForbidden();

    expectNotClosed($this->ticket);
});

test('an unknown ticket returns not found', function () {
    $this->actingAs($this->manager)
        ->post('/tickets/HD-ZZZZZZZZ/closing', ['expected_status' => 'resolved'])
        ->assertNotFound();
});

test('a technician explicitly granted tickets.close can close a ticket they can access', function () {
    // RF-14, regra 4: o técnico só encerra com a permissão explícita.
    Role::where('slug', 'technician')->sole()->permissions()
        ->attach(Permission::where('slug', 'tickets.close')->sole()->id, ['created_at' => now()]);

    postClosing($this->tech->fresh(), $this->ticket)->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Closed);
});

/* ---------- Encerramento válido ---------- */

test('a manager closes a resolved ticket', function () {
    $this->freezeSecond();

    postClosing($this->manager, $this->ticket)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number))
        ->assertSessionHas('success', 'Chamado encerrado. O encerramento foi registrado no histórico.');

    $ticket = $this->ticket->fresh();

    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->closed_at->equalTo(now()))->toBeTrue()
        ->and($ticket->ticket_number)->toBe($this->ticket->ticket_number);
});

/* ---------- Validação ---------- */

test('the expected status is required and must be a known status', function (mixed $expected) {
    $this->actingAs($this->manager)
        ->post(closingUrl($this->ticket), ['expected_status' => $expected])
        ->assertSessionHasErrors('expected_status');

    expectNotClosed($this->ticket);
})->with([null, '', 'archived', [['resolved']]]);

/* ---------- Estado do chamado ---------- */

test('only resolved tickets can be closed', function (TicketStatus $status) {
    $this->ticket->forceFill(['status' => $status])->save();

    // O status esperado é o real: não é conflito, é estado incompatível.
    postClosing($this->manager, $this->ticket)
        ->assertSessionHasErrors(['status' => 'Este chamado não pode ser encerrado no estado atual.']);

    expectNotClosed($this->ticket, $status);
})->with([
    'aberto' => TicketStatus::Open,
    'em atendimento' => TicketStatus::InProgress,
    'aguardando solicitante' => TicketStatus::AwaitingRequester,
    'pendente' => TicketStatus::Pending,
    'pendente terceiros' => TicketStatus::PendingThirdParty,
    'pendente departamentos' => TicketStatus::PendingDepartments,
]);

test('an already closed ticket is not closed again as a new transition', function () {
    postClosing($this->manager, $this->ticket)->assertSessionHasNoErrors();
    $closedAt = $this->ticket->fresh()->closed_at;
    $this->travel(1)->hours();

    postClosing($this->manager, $this->ticket, expected: 'closed')
        ->assertSessionHasErrors(['status' => 'Este chamado não pode ser encerrado no estado atual.']);

    expect($this->ticket->fresh()->closed_at->equalTo($closedAt))->toBeTrue()
        ->and(closedEvents())->toHaveCount(1);
});

test('the status change flow cannot close a ticket', function () {
    $this->actingAs($this->manager)
        ->put("/tickets/{$this->ticket->ticket_number}/status", ['status' => 'closed', 'expected_status' => 'resolved'])
        ->assertSessionHasErrors('status');

    expectNotClosed($this->ticket);
});

test('a ticket reopened while the modal was open is reported as a conflict and not closed', function () {
    // A tela foi aberta com "Resolvido"; o solicitante reabriu o chamado nesse meio-tempo (CHA-10).
    $this->actingAs($this->requester)
        ->post("/tickets/{$this->ticket->ticket_number}/reopening", ['justification' => 'Voltou a falhar.', 'expected_status' => 'resolved'])
        ->assertSessionHasNoErrors();

    postClosing($this->manager, $this->ticket, expected: 'resolved')
        ->assertSessionHasErrors('conflict')
        ->assertSessionMissing('success');

    $ticket = $this->ticket->fresh();

    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->closed_at)->toBeNull()
        ->and(closedEvents())->toHaveCount(0);
});

test('a repeated or concurrent submission closes the ticket only once', function () {
    $this->freezeSecond();
    postClosing($this->manager, $this->ticket, expected: 'resolved')->assertSessionHasNoErrors();
    $closedAt = now()->copy();

    $this->travel(5)->minutes();
    postClosing($this->manager, $this->ticket, expected: 'resolved')->assertSessionHasErrors('conflict');

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($this->ticket->fresh()->closed_at->equalTo($closedAt))->toBeTrue()
        ->and(closedEvents())->toHaveCount(1);
});

test('the action re-checks the locked row when the request was validated against a stale state', function () {
    // Simula a segunda de duas requisições simultâneas: a primeira já gravou o encerramento.
    $stale = $this->ticket->fresh();
    app(CloseTicket::class)($this->manager, $this->ticket->fresh(), TicketStatus::Resolved);

    expect(fn () => app(CloseTicket::class)($this->manager, $stale, TicketStatus::Resolved))
        ->toThrow(TicketStatusConflict::class);

    expect(closedEvents())->toHaveCount(1);
});

test('the action re-checks the permission on the locked row', function () {
    // Chamada direta, sem passar pelo Form Request: a autorização não depende só da entrada HTTP.
    expect(fn () => app(CloseTicket::class)($this->tech, $this->ticket->fresh(), TicketStatus::Resolved))
        ->toThrow(AuthorizationException::class);

    expectNotClosed($this->ticket);
});

/* ---------- Auditoria e integridade ---------- */

test('the history records resolved to closed with author and server time', function () {
    $this->freezeSecond();

    postClosing($this->manager, $this->ticket)->assertSessionHasNoErrors();

    $event = closedEvents()->sole();

    expect($event->ticket_id)->toBe($this->ticket->id)
        ->and($event->user_id)->toBe($this->manager->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue()
        ->and($event->old_values)->toBe(['status' => 'resolved', 'label' => 'Resolvido'])
        ->and($event->new_values)->toBe([
            'status' => 'closed',
            'label' => 'Encerrado',
            'closed_at' => now()->toIso8601String(),
        ]);
});

test('author, time and final status always come from the server', function () {
    $this->freezeSecond();

    postClosing($this->manager, $this->ticket, [
        'user_id' => $this->tech->id,
        'closed_at' => '2020-01-01 00:00:00',
        'created_at' => '2020-01-01 00:00:00',
        'status' => 'open',
    ])->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();
    $event = closedEvents()->sole();

    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->closed_at->equalTo(now()))->toBeTrue()
        ->and($event->user_id)->toBe($this->manager->id)
        ->and($event->created_at->equalTo(now()))->toBeTrue();
});

test('closing only changes the status and the closing time of the ticket', function () {
    $other = User::factory()->withRole('technician')->create();
    $before = $this->ticket->fresh()->getAttributes();

    postClosing($this->manager, $this->ticket, [
        'assigned_to' => $other->id,
        'requester_id' => $other->id,
        'department_id' => 999,
        'category_id' => 999,
        'priority' => 'low',
        'title' => 'Título manipulado',
        'description' => 'Descrição apagada',
        'ticket_number' => 'HD-AAAAAAAA',
        'resolution_summary' => 'Solução apagada',
        'resolved_at' => null,
        'resolution_due_at' => now()->addYear()->toDateTimeString(),
        'first_response_at' => now()->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $changed = ['status', 'closed_at', 'updated_at'];
    $after = $this->ticket->fresh()->getAttributes();

    expect(Arr::except($after, $changed))->toEqual(Arr::except($before, $changed))
        ->and($after['status'])->toBe('closed');
});

test('the resolution, comments, internal notes and previous history are preserved', function () {
    $public = TicketComment::factory()->create(['ticket_id' => $this->ticket->id, 'user_id' => $this->tech->id, 'body' => 'Peça trocada.']);
    $note = TicketComment::factory()->internal()->create(['ticket_id' => $this->ticket->id, 'user_id' => $this->tech->id, 'body' => 'Garantia do fornecedor.']);
    $comments = TicketComment::orderBy('id')->get()->map->getAttributes()->all();
    $resolutionEvent = TicketHistory::where('event_type', TicketEvent::Resolved)->sole()->getAttributes();

    postClosing($this->manager, $this->ticket)->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    expect($ticket->resolution_summary)->toBe(CLOSE_SOLUTION)
        ->and($ticket->resolved_at->equalTo($this->resolvedAt))->toBeTrue()
        ->and(TicketComment::orderBy('id')->get()->map->getAttributes()->all())->toBe($comments)
        ->and(TicketHistory::where('event_type', TicketEvent::Resolved)->sole()->getAttributes())->toBe($resolutionEvent)
        ->and(TicketHistory::count())->toBe(2)
        ->and($public->fresh())->not->toBeNull()
        ->and($note->fresh())->not->toBeNull();
});

test('a failure while recording the audit leaves nothing behind', function () {
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));
    $this->withoutExceptionHandling();

    expect(fn () => postClosing($this->manager, $this->ticket))->toThrow(RuntimeException::class, 'falha simulada na auditoria');

    expectNotClosed($this->ticket);
});

test('a failure while saving the ticket rolls back the audit event', function () {
    Ticket::saving(fn () => throw new RuntimeException('falha simulada no chamado'));
    $this->withoutExceptionHandling();

    expect(fn () => postClosing($this->manager, $this->ticket))->toThrow(RuntimeException::class, 'falha simulada no chamado');

    expectNotClosed($this->ticket);
});

/* ---------- SLA ---------- */

test('closing keeps deadlines, first response, pauses and the resolution result', function (string $resolvedOffset, string $result) {
    $this->freezeSecond();
    $recorded = [
        'first_response_due_at' => now()->subHours(5),
        'first_response_at' => now()->subHours(4), // violada: respondida depois do prazo
        'resolution_due_at' => $this->resolvedAt->copy()->modify($resolvedOffset),
    ];
    $this->ticket->forceFill($recorded)->save();
    $pause = $this->ticket->slaPauses()->forceCreate([
        'pause_status' => TicketStatus::PendingThirdParty,
        'reason' => 'Aguardando fornecedor',
        'started_at' => now()->subHours(3),
        'ended_at' => now()->subHours(2),
        'duration_seconds' => 3600,
        'created_by' => $this->tech->id,
    ]);
    $pauseBefore = $pause->fresh()->getAttributes();

    expect($this->ticket->fresh()->slaState()->value)->toBe($result);

    // O encerramento acontece bem depois do prazo: o resultado continua o da resolução.
    $this->travel(3)->days();
    postClosing($this->manager, $this->ticket)->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();

    foreach ($recorded as $column => $value) {
        expect($ticket->{$column}->equalTo($value))->toBeTrue();
    }
    expect($ticket->resolved_at->equalTo($this->resolvedAt))->toBeTrue()
        ->and($pause->fresh()->getAttributes())->toBe($pauseBefore)
        ->and($ticket->slaPauses()->count())->toBe(1)
        ->and($ticket->firstResponseSlaState()->value)->toBe('overdue')
        ->and($ticket->slaState()->value)->toBe($result);
})->with([
    'resolvido no prazo' => ['+1 hour', 'met'],
    'resolvido fora do prazo' => ['-1 hour', 'overdue'],
]);

/* ---------- Tela de detalhes ---------- */

test('only users with tickets.close are offered the closing of a resolved ticket', function () {
    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.close', true)
            ->where('closeForm', ['current' => 'resolved'])
            ->where('closure', null));

    foreach ([$this->tech, $this->requester, User::factory()->withRole('admin')->create()] as $user) {
        $this->actingAs($user)
            ->get("/tickets/{$this->ticket->ticket_number}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.close', false)
                ->where('closeForm', null));
    }
});

test('the closing is not offered outside of resolved', function (TicketStatus $status) {
    $this->ticket->forceFill(['status' => $status])->save();

    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.close', false)
            // Mantido para o modal mostrar o status que venceu após um conflito.
            ->where('closeForm.current', $status->value));
})->with([TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Pending, TicketStatus::Closed]);

test('after closing, the page shows the final status, the closure and the preserved resolution', function () {
    $this->freezeSecond();
    postClosing($this->manager, $this->ticket)->assertSessionHasNoErrors();

    foreach ([$this->requester, $this->tech, $this->manager] as $user) {
        $this->actingAs($user)
            ->get("/tickets/{$this->ticket->ticket_number}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.status', 'Encerrado')
                ->where('closure', ['closed_at' => now()->toIso8601String(), 'author' => 'Marina Souza'])
                ->where('resolution.summary', CLOSE_SOLUTION)
                ->where('sla.resolution.met_at', $this->resolvedAt->toIso8601String())
                ->where('can.close', false)
                ->where('can.reopen', false)
                ->where('can.comment', false));
    }

    // Auditoria (`audit.view`): o gestor vê a resolução e o encerramento, em ordem.
    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('history', 2)
            ->where('history.0.title', 'Chamado resolvido')
            ->where('history.0.summary', CLOSE_SOLUTION)
            ->where('history.1.title', 'Chamado encerrado')
            ->where('history.1.from', 'Resolvido')
            ->where('history.1.to', 'Encerrado')
            ->where('history.1.author', 'Marina Souza'));

    $row = collect($this->actingAs($this->requester)->get('/tickets')->viewData('page')['props']['tickets']['data'])
        ->firstWhere('number', $this->ticket->ticket_number);

    expect($row['status'])->toBe('Encerrado');
});

test('a closed ticket is not reopened by the requester nor changed by the assignee', function () {
    postClosing($this->manager, $this->ticket)->assertSessionHasNoErrors();

    // RF-13, regra 5, e RF-14, regra 8.
    $this->actingAs($this->requester)
        ->post("/tickets/{$this->ticket->ticket_number}/reopening", ['justification' => 'Voltou a falhar.', 'expected_status' => 'closed'])
        ->assertSessionHasErrors('status');

    $this->actingAs($this->tech)
        ->post("/tickets/{$this->ticket->ticket_number}/resolution", ['resolution_summary' => 'Nova solução', 'expected_status' => 'closed'])
        ->assertSessionHasErrors('status');

    $ticket = $this->ticket->fresh();

    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->assigned_to)->toBe($this->tech->id)
        ->and($ticket->resolution_summary)->toBe(CLOSE_SOLUTION)
        ->and(TicketHistory::count())->toBe(2);
});
