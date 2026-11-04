<?php

use App\Enums\ChatSessionStatus;
use App\Enums\TicketEvent;
use App\Events\Chat\ChatSessionChanged;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\ChatSessionEvent;
use App\Models\Department;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketHistory;
use App\Models\User;
use App\Support\ChatConsoleState;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/** Etapa 8 — encerramento e geração idempotente do chamado (RF-34, RN-CHAT-08, D-C5, D-C12). */
beforeEach(function () {
    config(['chat.enabled' => true]);
    Carbon::setTestNow('2026-11-04 10:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->department = Department::factory()->create(['name' => 'Financeiro']);
    $this->category = TicketCategory::factory()->create(['name' => 'Impressoras', 'is_active' => true]);
    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza', 'department_id' => $this->department->id]);
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->policy = SlaPolicy::factory()->create(['priority' => 'medium', 'first_response_minutes' => 30, 'resolution_minutes' => 480, 'is_active' => true]);

    // Entrou na fila às 09:00, foi atendida às 09:10; o técnico respondeu às 09:12.
    $this->session = ChatSession::factory()->active($this->tech)->create([
        'requester_id' => $this->requester->id,
        'department_id' => $this->department->id,
        'subject' => 'Impressora não imprime',
        'created_at' => '2026-11-04 09:00:00',
        'accepted_at' => '2026-11-04 09:10:00',
        'first_response_at' => '2026-11-04 09:12:00',
    ]);
});

function closePayload(array $override = []): array
{
    return [
        'outcome' => 'resolved',
        'title' => 'Impressora não imprime',
        'category_id' => test()->category->id,
        'summary' => 'Reinstalado o driver.',
        ...$override,
    ];
}

function closeChat(User $user, ChatSession $session, array $payload)
{
    return test()->actingAs($user)->postJson("/chat/sessions/{$session->id}/closure", $payload);
}

/* ---------- Resolvido ---------- */

test('closing as resolved creates one resolved ticket with the real chat times', function () {
    Event::fake([ChatSessionChanged::class]);

    closeChat($this->tech, $this->session, closePayload(['note' => 'Ignorada']))
        ->assertOk()
        ->assertJsonPath('status.value', 'ended')
        ->assertJsonPath('outcome.value', 'resolved')
        ->assertJsonPath('ticket_number', Ticket::sole()->ticket_number);

    $ticket = Ticket::sole();
    $session = $this->session->fresh();

    expect($ticket)
        ->title->toBe('Impressora não imprime')
        ->category_id->toBe($this->category->id)
        ->requester_id->toBe($this->requester->id)
        ->department_id->toBe($this->department->id)
        ->assigned_to->toBe($this->tech->id)
        ->status->value->toBe('resolved')
        ->priority->value->toBe('medium')
        ->resolution_summary->toBe('Reinstalado o driver.')
        ->and($ticket->created_at->toDateTimeString())->toBe('2026-11-04 09:00:00')
        ->and($ticket->first_response_at->toDateTimeString())->toBe('2026-11-04 09:12:00')
        ->and($ticket->resolved_at->toDateTimeString())->toBe('2026-11-04 10:00:00')
        // SLA de prioridade Média a partir da entrada na fila.
        ->and($ticket->sla_policy_id)->toBe($this->policy->id)
        ->and($ticket->first_response_due_at->toDateTimeString())->toBe('2026-11-04 09:30:00')
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-11-04 17:00:00')
        ->and($ticket->resolution_effective_seconds)->toBe(3600)
        ->and($ticket->first_response_breached_at)->toBeNull()
        ->and($ticket->resolution_breached_at)->toBeNull();

    expect($session)
        ->status->toBe(ChatSessionStatus::Ended)
        ->active_requester_id->toBeNull()
        ->ticket_id->toBe($ticket->id)
        ->outcome->value->toBe('resolved')
        ->and($session->ended_at->toDateTimeString())->toBe('2026-11-04 10:00:00');

    Event::assertDispatched(ChatSessionChanged::class, fn ($e) => $e->recipientIds === [$this->requester->id, $this->tech->id] && $e->type->value === 'ended');
});

test('the ticket history follows the real sequence with the chat instants', function () {
    closeChat($this->tech, $this->session, closePayload())->assertOk();

    $events = Ticket::sole()->histories()->orderBy('id')->get();

    expect($events->pluck('event_type')->map->value->all())->toBe(['created', 'self_assigned', 'resolved'])
        ->and($events[0]->created_at->toDateTimeString())->toBe('2026-11-04 09:00:00')
        ->and($events[0]->user_id)->toBe($this->requester->id)
        ->and($events[0]->new_values)->toMatchArray(['origin' => 'chat', 'chat_session_id' => $this->session->id, 'status' => 'open'])
        ->and($events[1]->created_at->toDateTimeString())->toBe('2026-11-04 09:10:00')
        ->and($events[1]->user_id)->toBe($this->tech->id)
        ->and($events[2]->created_at->toDateTimeString())->toBe('2026-11-04 10:00:00')
        ->and($events[2]->new_values['resolution_summary'])->toBe('Reinstalado o driver.');
});

test('the generated description summarizes the session without copying the conversation', function () {
    ChatMessage::forceCreate(['chat_session_id' => $this->session->id, 'user_id' => $this->requester->id, 'body' => 'SEGREDO-DA-CONVERSA', 'client_id' => (string) Str::uuid(), 'created_at' => now()]);

    closeChat($this->tech, $this->session, closePayload())->assertOk();

    expect(Ticket::sole()->description)
        ->toContain('Assunto informado: Impressora não imprime', "Atendimento via chat nº {$this->session->id}", 'Técnico: Carlos Lima', 'Resultado: Resolvido')
        ->not->toContain('SEGREDO-DA-CONVERSA');
});

test('a slow chat records the SLA breaches with the real times', function () {
    // Meta de primeira resposta de 5 min: o técnico respondeu 12 min depois da entrada; resolução em 30 min.
    $this->policy->update(['first_response_minutes' => 5, 'resolution_minutes' => 30]);

    closeChat($this->tech, $this->session, closePayload())->assertOk();

    $ticket = Ticket::sole();

    expect($ticket->first_response_breached_at)->not->toBeNull()
        ->and($ticket->resolution_breached_at)->not->toBeNull()
        ->and($ticket->histories()->where('event_type', TicketEvent::SlaBreached)->count())->toBe(2);
});

test('without an active SLA policy the ticket opens without deadlines', function () {
    $this->policy->update(['is_active' => false]);

    closeChat($this->tech, $this->session, closePayload())->assertOk();

    expect(Ticket::sole())->sla_policy_id->toBeNull()->first_response_due_at->toBeNull()->resolution_due_at->toBeNull()->resolution_effective_seconds->toBeNull();
});

/* ---------- Não resolvido ---------- */

test('closing as unresolved leaves the ticket in progress with the note and no summary required', function () {
    closeChat($this->tech, $this->session, closePayload(['outcome' => 'unresolved', 'summary' => null, 'note' => 'Voltar a testar amanhã.']))->assertOk();

    $ticket = Ticket::sole();

    expect($ticket)
        ->status->value->toBe('in_progress')
        ->assigned_to->toBe($this->tech->id)
        ->resolved_at->toBeNull()
        ->resolution_summary->toBeNull()
        ->description->toContain('Resultado: Não resolvido', 'Voltar a testar amanhã.')
        ->and($ticket->histories()->pluck('event_type')->map->value->all())->toBe(['created', 'self_assigned']);
});

/* ---------- Idempotência e concorrência lógica ---------- */

test('closing twice returns the same ticket and never creates another', function () {
    $first = closeChat($this->tech, $this->session, closePayload())->assertOk()->json('ticket_number');
    $second = closeChat($this->tech, $this->session, closePayload(['title' => 'Outro título']))->assertOk()->json('ticket_number');

    expect($second)->toBe($first)
        ->and(Ticket::count())->toBe(1)
        ->and(Ticket::sole()->title)->toBe('Impressora não imprime');
});

test('a repeated closing does not broadcast again', function () {
    closeChat($this->tech, $this->session, closePayload())->assertOk();
    Event::fake([ChatSessionChanged::class]);

    closeChat($this->tech, $this->session, closePayload())->assertOk();

    Event::assertNotDispatched(ChatSessionChanged::class);
});

test('the one-ticket-per-session guard also holds in the database', function () {
    closeChat($this->tech, $this->session, closePayload())->assertOk();

    expect(fn () => ChatSession::factory()->ended($this->tech)->create(['ticket_id' => Ticket::sole()->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('nothing is saved when the closing fails halfway', function () {
    // Falha na última gravação: o chamado e o histórico já criados na transação são desfeitos.
    Event::listen('eloquent.created: '.ChatSessionEvent::class, fn () => throw new RuntimeException('falha simulada'));

    closeChat($this->tech, $this->session, closePayload())->assertStatus(500);

    expect(Ticket::count())->toBe(0)
        ->and(TicketHistory::count())->toBe(0)
        ->and($this->session->fresh()->status)->toBe(ChatSessionStatus::Active)
        ->and($this->session->fresh()->ticket_id)->toBeNull();
});

/* ---------- Autorização e estado ---------- */

test('only the responsible technician closes the session', function (string $who) {
    $user = match ($who) {
        'requester' => $this->requester,
        'other technician' => User::factory()->withRole('technician')->create(),
        'manager' => User::factory()->withRole('manager')->create(),
        'admin' => User::factory()->withRole('admin')->create(),
    };

    closeChat($user, $this->session, closePayload())->assertStatus($who === 'other technician' ? 404 : 403);

    expect(Ticket::count())->toBe(0)
        ->and($this->session->fresh()->status)->toBe(ChatSessionStatus::Active);
})->with(['requester', 'other technician', 'manager', 'admin']);

test('a technician that is not responsible does not see the result of a finished session', function () {
    closeChat($this->tech, $this->session, closePayload())->assertOk();

    closeChat(User::factory()->withRole('manager')->create(), $this->session, closePayload())->assertForbidden();
});

test('waiting and cancelled sessions cannot be closed', function (string $state) {
    $session = match ($state) {
        'waiting' => ChatSession::factory()->create(['requester_id' => User::factory()->withRole('requester')->create(['department_id' => $this->department->id])->id]),
        'cancelled' => ChatSession::factory()->cancelled()->create(),
    };

    // O técnico não é participante de uma sessão sem técnico: nem enxerga o identificador.
    closeChat($this->tech, $session, closePayload())->assertNotFound();
})->with(['waiting', 'cancelled']);

test('guests and a disabled module cannot close sessions', function () {
    $this->postJson("/chat/sessions/{$this->session->id}/closure", closePayload())->assertUnauthorized();

    config(['chat.enabled' => false]);
    closeChat($this->tech, $this->session, closePayload())->assertNotFound();
});

/* ---------- Validação ---------- */

test('the closing data is validated', function (array $override, string $field) {
    closeChat($this->tech, $this->session, closePayload($override))->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(Ticket::count())->toBe(0);
})->with([
    'no outcome' => [['outcome' => null], 'outcome'],
    'unknown outcome' => [['outcome' => 'maybe'], 'outcome'],
    'no title' => [['title' => ''], 'title'],
    'long title' => [['title' => str_repeat('a', 256)], 'title'],
    'no category' => [['category_id' => null], 'category_id'],
    'missing category' => [['category_id' => 999999], 'category_id'],
    'resolved without summary' => [['summary' => ''], 'summary'],
    'long note' => [['note' => str_repeat('a', 2001)], 'note'],
]);

test('an inactive category is refused', function () {
    $this->category->update(['is_active' => false]);

    closeChat($this->tech, $this->session, closePayload())->assertUnprocessable()->assertJsonValidationErrors('category_id');
});

test('the browser cannot choose technician, status, dates or priority of the ticket', function () {
    closeChat($this->tech, $this->session, closePayload([
        'assigned_to' => User::factory()->create()->id,
        'status' => 'closed',
        'priority' => 'critical',
        'created_at' => '2000-01-01 00:00:00',
        'requester_id' => 999,
    ]))->assertOk();

    expect(Ticket::sole())
        ->assigned_to->toBe($this->tech->id)
        ->status->value->toBe('resolved')
        ->priority->value->toBe('medium')
        ->requester_id->toBe($this->requester->id)
        ->and(Ticket::sole()->created_at->toDateTimeString())->toBe('2026-11-04 09:00:00');
});

/* ---------- Depois do encerramento ---------- */

test('after the closing no more messages are accepted and the requester gets the ticket', function () {
    closeChat($this->tech, $this->session, closePayload())->assertOk();
    $number = Ticket::sole()->ticket_number;

    foreach ([$this->requester, $this->tech] as $user) {
        $this->actingAs($user)->postJson("/chat/sessions/{$this->session->id}/messages", ['body' => 'Oi', 'client_id' => (string) Str::uuid()])->assertStatus(409);
    }

    $this->actingAs($this->requester)->getJson("/chat/sessions/{$this->session->id}")
        ->assertJsonPath('ticket_number', $number)
        ->assertJsonPath('outcome.label', 'Resolvido');
    $this->actingAs($this->requester)->get('/chat')->assertInertia(fn ($page) => $page->where('session', null));
    // Libera a vaga: o solicitante pode pedir outro atendimento.
    expect($this->session->fresh()->active_requester_id)->toBeNull();
});

test('the technician frees the capacity slot when the session ends', function () {
    expect(ChatConsoleState::sessions($this->tech))->toHaveCount(1);

    closeChat($this->tech, $this->session, closePayload())->assertOk();

    expect(ChatConsoleState::sessions($this->tech))->toBeEmpty();
});

/* ---------- Conversa no chamado ---------- */

test('the ticket page carries the chat summary and everyone who sees the ticket reads the conversation', function () {
    ChatMessage::forceCreate(['chat_session_id' => $this->session->id, 'user_id' => $this->requester->id, 'body' => 'Olá <b>técnico</b>', 'client_id' => (string) Str::uuid(), 'created_at' => '2026-11-04 09:11:00']);
    ChatMessage::forceCreate(['chat_session_id' => $this->session->id, 'user_id' => $this->tech->id, 'body' => 'Já vou ver', 'client_id' => (string) Str::uuid(), 'created_at' => '2026-11-04 09:12:00']);
    closeChat($this->tech, $this->session, closePayload())->assertOk();
    $number = Ticket::sole()->ticket_number;

    foreach ([$this->requester, $this->tech] as $user) {
        $this->actingAs($user)->get("/tickets/{$number}")
            ->assertInertia(fn ($page) => $page->where('chatSession.messages', 2)->where('chatSession.technician', 'Carlos Lima'));

        $this->actingAs($user)->getJson("/tickets/{$number}/chat")
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.body', 'Olá <b>técnico</b>')
            ->assertJsonPath('messages.1.author.name', 'Carlos Lima');
    }
});

test('someone who cannot see the ticket cannot read its conversation', function () {
    closeChat($this->tech, $this->session, closePayload())->assertOk();
    $stranger = User::factory()->withRole('requester')->create(['department_id' => $this->department->id]);

    $this->actingAs($stranger)->getJson('/tickets/'.Ticket::sole()->ticket_number.'/chat')->assertForbidden();
});

test('an ordinary ticket has no chat summary and no conversation', function () {
    $ticket = Ticket::factory()->create(['requester_id' => $this->requester->id]);

    $this->actingAs($this->requester)->get("/tickets/{$ticket->ticket_number}")->assertInertia(fn ($page) => $page->where('chatSession', null));
    $this->actingAs($this->requester)->getJson("/tickets/{$ticket->ticket_number}/chat")->assertNotFound();
});

test('the audit history of the ticket shows the chat as the origin', function () {
    closeChat($this->tech, $this->session, closePayload())->assertOk();
    $manager = User::factory()->withRole('manager')->create();

    $response = $this->actingAs($manager)->getJson('/tickets/'.Ticket::sole()->ticket_number.'/audit')->assertOk();

    expect(json_encode($response->json('events.0'), JSON_UNESCAPED_UNICODE))->toContain("Atendimento via chat nº {$this->session->id}");
});
