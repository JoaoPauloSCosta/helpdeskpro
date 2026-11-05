<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Nova posição de um solicitante na fila (RF-27). Só a dele, no canal dele (RN-CHAT-02). */
class ChatQueuePositionChanged implements ShouldBroadcastNow
{
    public function __construct(public int $requesterId, public int $sessionId, public int $position) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("chat.user.{$this->requesterId}");
    }

    public function broadcastAs(): string
    {
        return 'chat.queue.position';
    }

    public function broadcastWith(): array
    {
        return ['session_id' => $this->sessionId, 'position' => $this->position, 'ahead' => $this->position - 1];
    }
}
