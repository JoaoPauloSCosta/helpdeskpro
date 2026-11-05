<?php

namespace App\Events\Chat;

use App\Enums\ChatEvent;
use App\Models\ChatSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Mudança no ciclo de vida da sessão (iniciada, cancelada, transferida, encerrada), para as pessoas envolvidas.
 * Payload mínimo: a tela busca o estado completo em GET /chat/sessions/{id}.
 */
class ChatSessionChanged implements ShouldBroadcastNow
{
    /** @param  list<int>  $recipientIds */
    public function __construct(public ChatSession $session, public ChatEvent $type, public array $recipientIds) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("chat.user.{$id}"), $this->recipientIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.session';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'type' => $this->type->value,
            'status' => $this->session->status->value,
            'technician' => $this->session->technician ? ['name' => $this->session->technician->name] : null,
            'ticket_number' => $this->session->ticket?->ticket_number,
        ];
    }
}
