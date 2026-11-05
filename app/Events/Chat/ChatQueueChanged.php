<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** A fila mudou (entrada, cancelamento, assunção): avisa a equipe com o total aguardando, sem dados pessoais. */
class ChatQueueChanged implements ShouldBroadcastNow
{
    public function __construct(public int $waiting) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.queue');
    }

    public function broadcastAs(): string
    {
        return 'chat.queue';
    }

    public function broadcastWith(): array
    {
        return ['waiting' => $this->waiting];
    }
}
