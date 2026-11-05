<?php

use App\Enums\ChatEvent;
use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatSession;
use App\Models\ChatSessionEvent;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/** Etapa 5 — pedido, fila FIFO, posição, cancelamento e "Atender próximo" (RF-26, RF-27, RF-29, RF-32). */
beforeEach(function () {
    config(['chat.enabled' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->department = Department::factory()->create(['name' => 'Financeiro']);
    $this->requester = chatRequester('Ana Souza');
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    putOnDuty($this->tech);
});

function chatRequester(string $name = 'Solicitante'): User
{
    return User::factory()->withRole('requester')->create(['name' => $name, 'department_id' => test()->department->id]);
}

function putOnDuty(User $tech, int $max = 3): ChatAgent
{
    return ChatAgent::forceCreate(['user_id' => $tech->id, 'is_available' => true, 'max_sessions' => $max, 'last_seen_at' => now()]);
}

function requestChat(User $requester, string $subject = 'Impressora não imprime')
{
    return test()->actingAs($requester)->postJson('/chat/sessions', ['subject' => $subject]);
}

function positionOf(User $requester, int $session): ?int
{
    return test()->actingAs($requester)->getJson("/chat/sessions/{$session}")->json('queue.position');
}

/* ---------- Pedido (RF-26) ---------- */

test('a requester enters the queue and sees only their own position', function () {
    $response = requestChat($this->requester)->assertCreated();
    $session = ChatSession::sole();

    $response->assertExactJson([
        'id' => $session->id,
        'subject' => 'Impressora não imprime',
        'status' => ['value' => 'waiting', 'label' => 'Aguardando'],
        'queue' => ['position' => 1, 'ahead' => 0],
        'no_technician_on_duty' => false,
        'technician' => null,
        'requester' => null,
        'outcome' => null,
        'ticket_number' => null,
        'created_at' => $session->created_at->toIso8601String(),
        'accepted_at' => null,
        'cancelled_at' => null,
        'ended_at' => null,
    ]);

    expect($session->requester_id)->toBe($this->requester->id)
        ->and($session->active_requester_id)->toBe($this->requester->id)
        ->and($session->department_id)->toBe($this->department->id)
        ->and($session->events()->sole()->event_type)->toBe(ChatEvent::Requested);
});

test('the requester and department come from the session, never from the payload', function () {
    $other = chatRequester();

    requestChat($this->requester)->assertCreated();
    $this->actingAs($this->requester)->postJson('/chat/sessions', [
        'subject' => 'x', 'requester_id' => $other->id, 'department_id' => 999, 'status' => 'active',
    ])->assertOk();

    expect(ChatSession::sole()->only(['requester_id', 'department_id', 'status']))
        ->toBe(['requester_id' => $this->requester->id, 'department_id' => $this->department->id, 'status' => ChatSessionStatus::Waiting]);
});

test('without a technician on duty the requester is offered a traditional ticket', function (Closure $setup) {
    $setup($this->tech);

    requestChat($this->requester)
        ->assertStatus(409)
        ->assertExactJson([
            'code' => 'no_technician_available',
            'message' => 'Não temos técnicos disponíveis para atendimento via chat neste momento. Você pode registrar um chamado para que sua solicitação seja atendida.',
            'ticket_url' => route('tickets.create'),
        ]);

    expect(ChatSession::count())->toBe(0);
})->with([
    'switched off' => [fn (User $tech) => ChatAgent::where('user_id', $tech->id)->update(['is_available' => false])],
    'not present' => [fn (User $tech) => ChatAgent::where('user_id', $tech->id)->update(['last_seen_at' => now()->subHour()])],
    'inactive' => [fn (User $tech) => $tech->forceFill(['is_active' => false])->save()],
]);

test('a busy technician still counts as on duty, so the requester waits in the queue (D-C2)', function () {
    ChatAgent::where('user_id', $this->tech->id)->update(['max_sessions' => 1]);
    ChatSession::factory()->active($this->tech)->create();

    requestChat($this->requester)->assertCreated();
});

test('repeating the request returns the open session instead of creating another', function () {
    $first = requestChat($this->requester)->assertCreated()->json('id');

    requestChat($this->requester, 'Outro assunto')->assertOk()->assertJsonPath('id', $first)->assertJsonPath('subject', 'Impressora não imprime');

    // Mesmo sem técnico de plantão, a sessão aberta continua sendo devolvida.
    ChatAgent::query()->update(['is_available' => false]);
    requestChat($this->requester)->assertOk()->assertJsonPath('id', $first);

    expect(ChatSession::count())->toBe(1)
        ->and(ChatSessionEvent::count())->toBe(1);
});

test('after cancelling, the requester can ask again', function () {
    $first = requestChat($this->requester)->json('id');
    $this->actingAs($this->requester)->postJson("/chat/sessions/{$first}/cancellation")->assertOk();

    $second = requestChat($this->requester)->assertCreated()->json('id');

    expect($second)->not->toBe($first);
});

test('the subject is required and limited', function (mixed $subject, string $message) {
    requestChat($this->requester, $subject)->assertUnprocessable()->assertJsonValidationErrors(['subject' => $message]);

    expect(ChatSession::count())->toBe(0);
})->with([
    'missing' => ['', 'Conte em poucas palavras o assunto do atendimento.'],
    'too long' => [str_repeat('a', 256), 'O assunto pode ter no máximo 255 caracteres.'],
]);

test('a requester without an active department cannot ask for chat', function (Closure $setup) {
    $setup($this->requester, $this->department);

    requestChat($this->requester)->assertUnprocessable()->assertJsonValidationErrors(['department']);

    expect(ChatSession::count())->toBe(0);
})->with([
    'no department' => [fn (User $user) => $user->forceFill(['department_id' => null])->save()],
    'inactive department' => [fn (User $user, Department $department) => $department->forceFill(['is_active' => false])->save()],
]);

test('users without chat.request cannot ask for chat', function (string $role) {
    $user = User::factory()->withRole($role)->create(['department_id' => $this->department->id]);

    requestChat($user)->assertForbidden();

    expect(ChatSession::count())->toBe(0);
})->with(['technician', 'manager', 'admin']);

test('guests and disabled module never reach the queue', function () {
    $this->postJson('/chat/sessions', ['subject' => 'x'])->assertUnauthorized();

    config(['chat.enabled' => false]);
    requestChat($this->requester)->assertNotFound();
});

/* ---------- Posição e privacidade (RF-27, RN-CHAT-01, RN-CHAT-02) ---------- */

test('positions follow arrival order and move up on cancel and accept', function () {
    [$a, $b, $c] = [chatRequester('A'), chatRequester('B'), chatRequester('C')];
    $ids = collect([$a, $b, $c])->map(fn (User $user) => requestChat($user)->json('id'));

    expect([positionOf($a, $ids[0]), positionOf($b, $ids[1]), positionOf($c, $ids[2])])->toBe([1, 2, 3]);

    $this->actingAs($a)->postJson("/chat/sessions/{$ids[0]}/cancellation")->assertOk();
    expect([positionOf($b, $ids[1]), positionOf($c, $ids[2])])->toBe([1, 2]);

    $this->actingAs($this->tech)->postJson('/chat/queue/next')->assertOk()->assertJsonPath('id', $ids[1]);
    expect(positionOf($b, $ids[1]))->toBeNull()
        ->and(positionOf($c, $ids[2]))->toBe(1);

    $this->actingAs($c)->getJson("/chat/sessions/{$ids[2]}")->assertJsonPath('queue', ['position' => 1, 'ahead' => 0]);
});

test('a session of someone else does not exist for the requester or an unrelated technician', function () {
    $other = chatRequester();
    $id = requestChat($other)->json('id');
    $outsider = User::factory()->withRole('technician')->create();

    $this->actingAs($this->requester)->getJson("/chat/sessions/{$id}")->assertNotFound();
    $this->actingAs($this->requester)->postJson("/chat/sessions/{$id}/cancellation")->assertNotFound();
    $this->actingAs($outsider)->getJson("/chat/sessions/{$id}")->assertNotFound();
    $this->actingAs($this->requester)->getJson('/chat/sessions/999999')->assertNotFound();

    expect(ChatSession::find($id)->status)->toBe(ChatSessionStatus::Waiting);
});

test('the manager sees any session with the requester name', function () {
    $id = requestChat($this->requester)->json('id');
    $manager = User::factory()->withRole('manager')->create();

    $this->actingAs($manager)->getJson("/chat/sessions/{$id}")->assertOk()->assertJsonPath('requester', ['name' => 'Ana Souza']);
});

/* ---------- Cancelamento (RF-32) ---------- */

test('the requester cancels while waiting, and repeating it changes nothing', function () {
    $id = requestChat($this->requester)->json('id');

    $this->actingAs($this->requester)->postJson("/chat/sessions/{$id}/cancellation")
        ->assertOk()
        ->assertJsonPath('status.value', 'cancelled')
        ->assertJsonPath('queue', null);
    $this->actingAs($this->requester)->postJson("/chat/sessions/{$id}/cancellation")->assertOk();

    $session = ChatSession::find($id);

    expect($session->active_requester_id)->toBeNull()
        ->and($session->cancelled_at)->not->toBeNull()
        ->and($session->events()->where('event_type', ChatEvent::Cancelled)->count())->toBe(1);
});

test('a session already taken by a technician cannot be cancelled', function () {
    $id = requestChat($this->requester)->json('id');
    $this->actingAs($this->tech)->postJson('/chat/queue/next')->assertOk();

    $this->actingAs($this->requester)->postJson("/chat/sessions/{$id}/cancellation")
        ->assertStatus(409)
        ->assertJsonPath('message', 'O atendimento já foi iniciado e não pode mais ser cancelado.');

    expect(ChatSession::find($id)->status)->toBe(ChatSessionStatus::Active);
});

test('the technician of a session cannot cancel it for the requester', function () {
    $id = requestChat($this->requester)->json('id');
    $this->actingAs($this->tech)->postJson('/chat/queue/next')->assertOk();

    $this->actingAs($this->tech)->postJson("/chat/sessions/{$id}/cancellation")->assertForbidden();
});

/* ---------- Fila operacional e "Atender próximo" (RF-29) ---------- */

test('staff see the queue in FIFO order without identifiers or names', function (string $role) {
    requestChat($this->requester, 'Primeiro');
    requestChat(chatRequester('Bruno'), 'Segundo');
    $viewer = $role === 'technician' ? $this->tech : User::factory()->withRole($role)->create();

    $response = $this->actingAs($viewer)->getJson('/chat/queue')->assertOk();

    expect($response->json('waiting'))->toBe(2)
        ->and(collect($response->json('entries'))->map(fn ($entry) => array_keys($entry))->unique()->sole())
        ->toBe(['position', 'subject', 'department', 'waiting_since'])
        ->and(collect($response->json('entries'))->pluck('subject')->all())->toBe(['Primeiro', 'Segundo'])
        ->and($response->json('entries.0.department'))->toBe('Financeiro');
})->with(['technician', 'manager']);

test('requesters and admins do not see the operational queue', function (string $role) {
    $user = $role === 'requester' ? $this->requester : User::factory()->withRole($role)->create();

    $this->actingAs($user)->getJson('/chat/queue')->assertForbidden();
})->with(['requester', 'admin']);

test('accepting next takes the oldest waiting session and both sides see it', function () {
    $first = requestChat($this->requester)->json('id');
    requestChat(chatRequester());

    $this->actingAs($this->tech)->postJson('/chat/queue/next')
        ->assertOk()
        ->assertJsonPath('id', $first)
        ->assertJsonPath('status.value', 'active')
        ->assertJsonPath('requester', ['name' => 'Ana Souza']);

    $this->actingAs($this->requester)->getJson("/chat/sessions/{$first}")
        ->assertJsonPath('technician', ['name' => 'Carlos Lima'])
        ->assertJsonPath('queue', null);

    $session = ChatSession::find($first);

    expect($session->technician_id)->toBe($this->tech->id)
        ->and($session->accepted_at)->not->toBeNull()
        ->and($session->events()->where('event_type', ChatEvent::Accepted)->sole()->user_id)->toBe($this->tech->id);
});

test('accepting next with an empty queue is refused', function () {
    $this->actingAs($this->tech)->postJson('/chat/queue/next')
        ->assertStatus(409)
        ->assertJsonPath('message', 'Não há solicitantes aguardando.');
});

test('a technician at the limit or unavailable cannot accept, and the session keeps its place', function () {
    $id = requestChat($this->requester)->json('id');
    ChatAgent::where('user_id', $this->tech->id)->update(['max_sessions' => 1]);
    ChatSession::factory()->active($this->tech)->create();

    $this->actingAs($this->tech)->postJson('/chat/queue/next')
        ->assertStatus(409)->assertJsonPath('message', 'Você atingiu o limite de atendimentos simultâneos.');

    ChatAgent::where('user_id', $this->tech->id)->update(['max_sessions' => 3, 'is_available' => false]);

    $this->actingAs($this->tech)->postJson('/chat/queue/next')
        ->assertStatus(409)->assertJsonPath('message', 'Você não está disponível para novos atendimentos.');

    expect(positionOf($this->requester, $id))->toBe(1);
});

test('users without chat.accept_next cannot accept', function (string $role) {
    requestChat($this->requester);
    $user = User::factory()->withRole($role)->create();
    putOnDuty($user);

    $this->actingAs($user)->postJson('/chat/queue/next')->assertForbidden();

    expect(ChatSession::sole()->status)->toBe(ChatSessionStatus::Waiting);
})->with(['manager', 'admin', 'requester']);

test('two technicians take consecutive sessions in arrival order', function () {
    $other = User::factory()->withRole('technician')->create();
    putOnDuty($other);
    $first = requestChat($this->requester)->json('id');
    $second = requestChat(chatRequester())->json('id');

    $this->actingAs($other)->postJson('/chat/queue/next')->assertJsonPath('id', $first);
    $this->actingAs($this->tech)->postJson('/chat/queue/next')->assertJsonPath('id', $second);

    expect(ChatSession::find($first)->technician_id)->toBe($other->id)
        ->and(ChatSession::find($second)->technician_id)->toBe($this->tech->id);
});
