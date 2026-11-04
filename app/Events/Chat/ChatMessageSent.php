<?php

namespace App\Events\Chat;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Nova mensagem (RF-30), para os participantes da sessão. Disparado depois do commit (ChatBroadcast). */
class ChatMessageSent implements ShouldBroadcastNow
{
    /** @param  list<int>  $recipientIds */
    public function __construct(public ChatMessage $message, public array $recipientIds) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("chat.user.{$id}"), $this->recipientIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    public function broadcastWith(): array
    {
        return $this->message->toPayload();
    }
}
