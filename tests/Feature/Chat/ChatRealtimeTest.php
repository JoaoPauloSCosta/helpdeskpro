<?php

use App\Enums\ChatEvent;
use App\Events\Chat\ChatQueueChanged;
use App\Events\Chat\ChatQueuePositionChanged;
use App\Events\Chat\ChatSessionChanged;
use App\Models\ChatAgent;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Etapa 6 — canais privados e avisos em tempo real (docs/chat/02-system-design-chat.md, 6). */
beforeEach(function () {
    config(['chat.enabled' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->department = Department::factory()->create();
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    ChatAgent::forceCreate(['user_id' => $this->tech->id, 'is_available' => true, 'max_sessions' => 3, 'last_seen_at' => now()]);
});

function realtimeRequester(): User
{
    return User::factory()->withRole('requester')->create(['department_id' => test()->department->id]);
}

/** Reverb com credenciais de teste: a autorização de canal é assinada localmente, sem servidor. */
function useReverbDriver(string $host = '127.0.0.1', int $port = 8081): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options.host' => $host,
        'broadcasting.connections.reverb.options.port' => $port,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    // Os canais foram registrados no boot, no driver de teste (null); registra de novo no Reverb.
    require base_path('routes/channels.php');
}

function authorizeChannel(User $user, string $channel)
{
    return test()->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"]);
}

/* ---------- Autorização dos canais ---------- */

test('each user subscribes only to their own channel', function () {
    useReverbDriver();
    $requester = realtimeRequester();

    authorizeChannel($requester, "chat.user.{$requester->id}")->assertOk()->assertJsonStructure(['auth']);
    authorizeChannel($requester, "chat.user.{$this->tech->id}")->assertForbidden();
});

test('inactive users and a disabled module get no channel', function () {
    useReverbDriver();
    $requester = realtimeRequester();

    config(['chat.enabled' => false]);
    authorizeChannel($requester, "chat.user.{$requester->id}")->assertForbidden();

    config(['chat.enabled' => true]);
    $requester->forceFill(['is_active' => false])->save();
    authorizeChannel($requester->fresh(), "chat.user.{$requester->id}")->assertForbidden();
});

test('only queue viewers subscribe to the queue channel', function () {
    useReverbDriver();

    authorizeChannel($this->tech, 'chat.queue')->assertOk();
    authorizeChannel(User::factory()->withRole('manager')->create(), 'chat.queue')->assertOk();
    authorizeChannel(realtimeRequester(), 'chat.queue')->assertForbidden();
    authorizeChannel(User::factory()->withRole('admin')->create(), 'chat.queue')->assertForbidden();
});

test('guests cannot authorize any channel', function () {
    useReverbDriver();

    $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-chat.queue'])->assertForbidden();
});

/* ---------- Avisos do ciclo de vida ---------- */

test('entering the queue tells the staff the total and each waiting requester only their position', function () {
    Event::fake([ChatQueueChanged::class, ChatQueuePositionChanged::class]);
    $first = realtimeRequester();
    $second = realtimeRequester();

    $this->actingAs($first)->postJson('/chat/sessions', ['subject' => 'A'])->assertCreated();
    $this->actingAs($second)->postJson('/chat/sessions', ['subject' => 'B'])->assertCreated();

    Event::assertDispatched(ChatQueueChanged::class, fn ($e) => $e->waiting === 2 && $e->broadcastOn()->name === 'private-chat.queue');
    Event::assertDispatched(ChatQueuePositionChanged::class, fn ($e) => $e->requesterId === $second->id
        && $e->position === 2
        && $e->broadcastOn()->name === "private-chat.user.{$second->id}"
        && $e->broadcastWith() === ['session_id' => ChatSession::firstWhere('requester_id', $second->id)->id, 'position' => 2, 'ahead' => 1]);
});

test('accepting tells the requester and the technician, and moves everyone else up', function () {
    $first = realtimeRequester();
    $second = realtimeRequester();
    $this->actingAs($first)->postJson('/chat/sessions', ['subject' => 'A']);
    $this->actingAs($second)->postJson('/chat/sessions', ['subject' => 'B']);
    Event::fake([ChatSessionChanged::class, ChatQueuePositionChanged::class, ChatQueueChanged::class]);

    $this->actingAs($this->tech)->postJson('/chat/queue/next')->assertOk();

    Event::assertDispatched(ChatSessionChanged::class, fn ($e) => $e->type === ChatEvent::Accepted
        && collect($e->broadcastOn())->map->name->sort()->values()->all() === collect(["private-chat.user.{$first->id}", "private-chat.user.{$this->tech->id}"])->sort()->values()->all()
        && $e->broadcastWith()['technician'] === ['name' => 'Carlos Lima']
        && $e->broadcastWith()['status'] === 'active');
    Event::assertDispatched(ChatQueuePositionChanged::class, fn ($e) => $e->requesterId === $second->id && $e->position === 1);
    Event::assertNotDispatched(ChatQueuePositionChanged::class, fn ($e) => $e->requesterId === $first->id);
    Event::assertDispatched(ChatQueueChanged::class, fn ($e) => $e->waiting === 1);
});

test('cancelling tells the queue once; repeating it sends nothing', function () {
    $requester = realtimeRequester();
    $id = $this->actingAs($requester)->postJson('/chat/sessions', ['subject' => 'A'])->json('id');
    Event::fake([ChatSessionChanged::class, ChatQueueChanged::class]);

    $this->actingAs($requester)->postJson("/chat/sessions/{$id}/cancellation")->assertOk();
    $this->actingAs($requester)->postJson("/chat/sessions/{$id}/cancellation")->assertOk();

    Event::assertDispatchedTimes(ChatQueueChanged::class, 1);
    Event::assertDispatched(ChatSessionChanged::class, fn ($e) => $e->type === ChatEvent::Cancelled);
});

test('no event ever carries another requester data to a requester', function () {
    $first = realtimeRequester();
    $second = realtimeRequester();
    Event::fake([ChatQueuePositionChanged::class]);

    $this->actingAs($first)->postJson('/chat/sessions', ['subject' => 'Assunto sigiloso']);
    $this->actingAs($second)->postJson('/chat/sessions', ['subject' => 'Outro assunto']);

    Event::assertDispatched(ChatQueuePositionChanged::class, fn ($e) => array_keys($e->broadcastWith()) === ['session_id', 'position', 'ahead']);
});

/* ---------- Falha do serviço de tempo real (RN-CHAT-07) ---------- */

test('when the realtime server is down the message is still saved and the failure is logged without its text', function () {
    useReverbDriver(port: 1); // porta fechada
    Log::spy();
    $requester = realtimeRequester();
    $session = ChatSession::factory()->active($this->tech)->create(['requester_id' => $requester->id]);

    $this->actingAs($requester)->postJson("/chat/sessions/{$session->id}/messages", ['body' => 'Texto sigiloso', 'client_id' => (string) Str::uuid()])
        ->assertCreated();

    expect(ChatMessage::sole()->body)->toBe('Texto sigiloso');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Falha ao transmitir evento do chat.'
        && $context['event'] === 'ChatMessageSent'
        && $context['session_id'] === $session->id
        && ! str_contains(json_encode($context), 'Texto sigiloso'));
});

test('the queue keeps working when the realtime server is down', function () {
    useReverbDriver(port: 1);
    $requester = realtimeRequester();

    $id = $this->actingAs($requester)->postJson('/chat/sessions', ['subject' => 'A'])->assertCreated()->json('id');
    $this->actingAs($this->tech)->postJson('/chat/queue/next')->assertOk()->assertJsonPath('id', $id);
});
