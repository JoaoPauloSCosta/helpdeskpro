<?php

use App\Enums\ChatEvent;
use App\Enums\ChatOutcome;
use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\ChatSessionEvent;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/** Estrutura do chat (Etapa 3): restrições que o banco garante sozinho, mesmo com erro de lógica ou concorrência. */
function chatMessage(ChatSession $session, User $author, string $clientId): ChatMessage
{
    return ChatMessage::forceCreate([
        'chat_session_id' => $session->id,
        'user_id' => $author->id,
        'body' => 'Olá',
        'client_id' => $clientId,
        'created_at' => now(),
    ]);
}

test('a requester can have only one open session at a time', function () {
    $session = ChatSession::factory()->create();

    expect(fn () => ChatSession::factory()->create(['requester_id' => $session->requester_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('closed sessions free the requester to open a new one', function (string $state) {
    $tech = User::factory()->create();
    $closed = ChatSession::factory()->{$state}(...($state === 'ended' ? [$tech] : []))->create();

    $new = ChatSession::factory()->create(['requester_id' => $closed->requester_id]);

    expect($closed->fresh()->active_requester_id)->toBeNull()
        ->and($new->active_requester_id)->toBe($closed->requester_id)
        ->and(ChatSession::where('requester_id', $closed->requester_id)->count())->toBe(2);
})->with(['cancelled', 'ended']);

test('a ticket is linked to at most one session', function () {
    $tech = User::factory()->create();
    $ticket = Ticket::factory()->create();
    ChatSession::factory()->ended($tech)->create(['ticket_id' => $ticket->id]);

    expect(fn () => ChatSession::factory()->ended($tech)->create(['ticket_id' => $ticket->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a resent message with the same client id is rejected within the session only', function () {
    $session = ChatSession::factory()->create();
    $other = ChatSession::factory()->create();
    $clientId = (string) Str::uuid();

    chatMessage($session, $session->requester, $clientId);
    chatMessage($other, $other->requester, $clientId);

    expect(fn () => chatMessage($session, $session->requester, $clientId))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(ChatMessage::count())->toBe(2);
});

test('a technician has a single chat agent row', function () {
    $user = User::factory()->create();
    ChatAgent::forceCreate(['user_id' => $user->id]);

    expect(fn () => ChatAgent::forceCreate(['user_id' => $user->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a new chat agent starts unavailable, unseen and with the default capacity', function () {
    $agent = ChatAgent::forceCreate(['user_id' => User::factory()->create()->id])->fresh();

    expect($agent->is_available)->toBeFalse()
        ->and($agent->last_seen_at)->toBeNull()
        ->and($agent->max_sessions)->toBe(config('chat.default_capacity'));
});

test('users with chat records cannot be deleted', function () {
    $session = ChatSession::factory()->create();

    expect(fn () => $session->requester->delete())->toThrow(QueryException::class);
});

test('relations and casts load the stored values', function () {
    $tech = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $session = ChatSession::factory()->ended($tech)->create(['ticket_id' => $ticket->id, 'outcome' => ChatOutcome::Resolved]);
    chatMessage($session, $tech, (string) Str::uuid());
    ChatSessionEvent::forceCreate([
        'chat_session_id' => $session->id,
        'user_id' => $tech->id,
        'event_type' => ChatEvent::Ended,
        'data' => ['outcome' => 'resolved'],
        'created_at' => now(),
    ]);
    ChatAgent::forceCreate(['user_id' => $tech->id]);

    $session = $session->fresh();

    expect($session->status)->toBe(ChatSessionStatus::Ended)
        ->and($session->outcome)->toBe(ChatOutcome::Resolved)
        ->and($session->technician->is($tech))->toBeTrue()
        ->and($session->requester)->not->toBeNull()
        ->and($session->department)->not->toBeNull()
        ->and($session->ticket->is($ticket))->toBeTrue()
        ->and($ticket->chatSession->is($session))->toBeTrue()
        ->and($session->messages->sole()->author->is($tech))->toBeTrue()
        ->and($session->events->sole()->event_type)->toBe(ChatEvent::Ended)
        ->and($session->events->sole()->data)->toBe(['outcome' => 'resolved'])
        ->and($tech->chatAgent)->not->toBeNull();
});
