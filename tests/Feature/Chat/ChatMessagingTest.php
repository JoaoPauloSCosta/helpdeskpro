<?php

use App\Events\Chat\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/** Etapa 6 — mensagens persistidas, idempotentes e recuperáveis (RF-30, RF-33, RN-CHAT-07). */
beforeEach(function () {
    config(['chat.enabled' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $department = Department::factory()->create();
    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza', 'department_id' => $department->id]);
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->session = ChatSession::factory()->active($this->tech)->create(['requester_id' => $this->requester->id]);
});

function sendChat(User $author, ChatSession $session, string $body = 'Olá', ?string $clientId = null)
{
    return test()->actingAs($author)->postJson("/chat/sessions/{$session->id}/messages", [
        'body' => $body,
        'client_id' => $clientId ?? (string) Str::uuid(),
    ]);
}

/* ---------- Envio ---------- */

test('both participants send messages that are persisted and broadcast to both', function () {
    Event::fake([ChatMessageSent::class]);
    $clientId = (string) Str::uuid();

    $response = sendChat($this->requester, $this->session, '  Minha impressora parou  ', $clientId)->assertCreated();
    $message = ChatMessage::sole();

    $response->assertExactJson([
        'id' => $message->id,
        'session_id' => $this->session->id,
        'author' => ['id' => $this->requester->id, 'name' => 'Ana Souza'],
        'body' => 'Minha impressora parou',
        'client_id' => $clientId,
        'created_at' => $message->created_at->toIso8601String(),
    ]);

    sendChat($this->tech, $this->session, 'Vou verificar')->assertCreated();

    Event::assertDispatchedTimes(ChatMessageSent::class, 2);
    Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $event) => collect($event->broadcastOn())->map->name->sort()->values()->all()
        === collect(["private-chat.user.{$this->requester->id}", "private-chat.user.{$this->tech->id}"])->sort()->values()->all());
});

test('resending the same message returns the stored one without duplicating or rebroadcasting', function () {
    Event::fake([ChatMessageSent::class]);
    $clientId = (string) Str::uuid();

    $first = sendChat($this->requester, $this->session, 'Olá', $clientId)->assertCreated()->json('id');
    sendChat($this->requester, $this->session, 'Olá', $clientId)->assertOk()->assertJsonPath('id', $first);

    expect(ChatMessage::count())->toBe(1);
    Event::assertDispatchedTimes(ChatMessageSent::class, 1);
});

test('a resend after the session ended still returns the stored message', function () {
    $clientId = (string) Str::uuid();
    $id = sendChat($this->requester, $this->session, 'Olá', $clientId)->json('id');
    $this->session->forceFill(['status' => 'ended', 'active_requester_id' => null, 'ended_at' => now()])->save();

    sendChat($this->requester, $this->session, 'Olá', $clientId)->assertOk()->assertJsonPath('id', $id);
});

test('a client id already used by the other participant is refused', function () {
    $clientId = (string) Str::uuid();
    sendChat($this->requester, $this->session, 'Olá', $clientId)->assertCreated();

    sendChat($this->tech, $this->session, 'Oi', $clientId)->assertStatus(409);

    expect(ChatMessage::count())->toBe(1);
});

test('sessions that are not in progress do not accept messages', function (string $state) {
    $session = match ($state) {
        'waiting' => ChatSession::factory()->create(['requester_id' => User::factory()->withRole('requester')->create()->id]),
        'ended' => ChatSession::factory()->ended($this->tech)->create(['requester_id' => $this->requester->id]),
        'cancelled' => ChatSession::factory()->cancelled()->create(['requester_id' => $this->requester->id]),
    };

    sendChat($session->requester, $session)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Este atendimento não está em andamento. A mensagem não foi enviada.');

    expect(ChatMessage::count())->toBe(0);
})->with(['waiting', 'ended', 'cancelled']);

test('people outside the session cannot send or even see it', function () {
    $outsider = User::factory()->withRole('technician')->create();
    $otherRequester = User::factory()->withRole('requester')->create();

    sendChat($outsider, $this->session)->assertNotFound();
    sendChat($otherRequester, $this->session)->assertNotFound();
    test()->actingAs($outsider)->getJson("/chat/sessions/{$this->session->id}/messages")->assertNotFound();

    expect(ChatMessage::count())->toBe(0);
});

test('the manager can read a conversation but not write in it', function () {
    sendChat($this->requester, $this->session, 'Olá')->assertCreated();
    $manager = User::factory()->withRole('manager')->create();

    $this->actingAs($manager)->getJson("/chat/sessions/{$this->session->id}/messages")->assertOk()->assertJsonCount(1, 'messages');
    sendChat($manager, $this->session)->assertForbidden();
});

test('a participant who lost chat.send_message cannot send', function () {
    $role = $this->tech->role;
    $role->permissions()->detach($role->permissions()->where('slug', 'chat.send_message')->pluck('permissions.id'));

    sendChat($this->tech->fresh(), $this->session)->assertForbidden();
});

test('the message must have text within the limit and a valid client id', function (array $payload, string $field) {
    $this->actingAs($this->requester)->postJson("/chat/sessions/{$this->session->id}/messages", [
        'body' => 'Olá', 'client_id' => (string) Str::uuid(), ...$payload,
    ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(ChatMessage::count())->toBe(0);
})->with([
    'empty' => [['body' => ''], 'body'],
    'only spaces' => [['body' => '    '], 'body'],
    'too long' => [['body' => str_repeat('a', 2001)], 'body'],
    'no client id' => [['client_id' => null], 'client_id'],
    'invalid client id' => [['client_id' => 'abc'], 'client_id'],
]);

test('markup is stored as plain text, exactly as typed', function () {
    sendChat($this->requester, $this->session, '<script>alert(1)</script> <b>oi</b>')->assertCreated();

    expect(ChatMessage::sole()->body)->toBe('<script>alert(1)</script> <b>oi</b>');
});

test('the first technician message records the first response once', function () {
    $this->freezeSecond();
    sendChat($this->requester, $this->session, 'Olá');
    expect($this->session->fresh()->first_response_at)->toBeNull();

    sendChat($this->tech, $this->session, 'Bom dia');
    $first = $this->session->fresh()->first_response_at;
    expect($first->equalTo(now()))->toBeTrue();

    $this->travel(5)->minutes();
    sendChat($this->tech, $this->session, 'Mais uma');
    expect($this->session->fresh()->first_response_at->equalTo($first))->toBeTrue();
});

/* ---------- Leitura incremental e reconexão (RF-33) ---------- */

test('history is read in order and incrementally after the last known id', function () {
    foreach (range(1, 3) as $i) {
        sendChat($i % 2 ? $this->requester : $this->tech, $this->session, "Mensagem {$i}");
    }
    $ids = ChatMessage::orderBy('id')->pluck('id');

    $this->actingAs($this->requester)->getJson("/chat/sessions/{$this->session->id}/messages")
        ->assertOk()
        ->assertJsonPath('messages.*.body', ['Mensagem 1', 'Mensagem 2', 'Mensagem 3'])
        ->assertJsonPath('has_more', false);

    $this->actingAs($this->tech)->getJson("/chat/sessions/{$this->session->id}/messages?after_id={$ids[0]}")
        ->assertJsonPath('messages.*.id', [$ids[1], $ids[2]]);
});

test('history comes in pages of 50', function () {
    foreach (range(1, 51) as $i) {
        ChatMessage::forceCreate(['chat_session_id' => $this->session->id, 'user_id' => $this->requester->id, 'body' => "m{$i}", 'client_id' => (string) Str::uuid(), 'created_at' => now()]);
    }

    $page = $this->actingAs($this->requester)->getJson("/chat/sessions/{$this->session->id}/messages")->assertJsonCount(50, 'messages')->assertJsonPath('has_more', true);
    $last = $page->json('messages.49.id');

    $this->actingAs($this->requester)->getJson("/chat/sessions/{$this->session->id}/messages?after_id={$last}")
        ->assertJsonCount(1, 'messages')->assertJsonPath('has_more', false)->assertJsonPath('messages.0.body', 'm51');
});

test('the conversation stays readable after the session ends', function () {
    sendChat($this->requester, $this->session, 'Olá');
    $this->session->forceFill(['status' => 'ended', 'active_requester_id' => null, 'ended_at' => now()])->save();

    $this->actingAs($this->requester)->getJson("/chat/sessions/{$this->session->id}/messages")->assertJsonCount(1, 'messages');
});

/* ---------- Lidas ---------- */

test('each participant marks messages as read and the mark never goes back', function () {
    $first = sendChat($this->tech, $this->session, 'Olá')->json('id');
    $second = sendChat($this->tech, $this->session, 'Tudo bem?')->json('id');

    $this->actingAs($this->requester)->postJson("/chat/sessions/{$this->session->id}/read", ['last_id' => $second])->assertNoContent();
    $this->actingAs($this->requester)->postJson("/chat/sessions/{$this->session->id}/read", ['last_id' => $first])->assertNoContent();
    // Um id além da última mensagem é limitado à última.
    $this->actingAs($this->tech)->postJson("/chat/sessions/{$this->session->id}/read", ['last_id' => 999999])->assertNoContent();

    expect($this->session->fresh()->only(['requester_last_read_id', 'technician_last_read_id']))
        ->toBe(['requester_last_read_id' => $second, 'technician_last_read_id' => $second]);
});

test('only participants mark messages as read', function () {
    sendChat($this->tech, $this->session, 'Olá');
    $manager = User::factory()->withRole('manager')->create();

    $this->actingAs($manager)->postJson("/chat/sessions/{$this->session->id}/read", ['last_id' => 1])->assertForbidden();
});

/* ---------- Preferência de som (RF-31) ---------- */

test('each user turns the chat sound on and off for themselves', function () {
    $this->actingAs($this->requester)->putJson('/chat/preferences', ['sound_enabled' => false])
        ->assertOk()->assertExactJson(['sound_enabled' => false]);

    expect($this->requester->fresh()->chat_sound_enabled)->toBeFalse()
        ->and($this->tech->fresh()->chat_sound_enabled)->toBeTrue();

    $this->actingAs($this->requester)->putJson('/chat/preferences', ['sound_enabled' => 'talvez'])->assertUnprocessable();
});
