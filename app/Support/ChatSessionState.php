<?php

namespace App\Support;

use App\Models\ChatAgent;
use App\Models\ChatSession;
use App\Models\User;

/**
 * Estado de uma sessão para quem a consulta (RF-27, RN-CHAT-02). Lista fechada de campos: o solicitante vê só a
 * própria posição e o nome do técnico; nada de outros solicitantes. Quem não é o solicitante (técnico,
 * gestor) vê também o nome dele.
 */
class ChatSessionState
{
    /** @return array<string, mixed> */
    public static function for(ChatSession $session, User $viewer): array
    {
        $session->loadMissing(['technician:id,name', 'requester:id,name', 'ticket:id,ticket_number']);
        $position = $session->queuePosition();

        return [
            'id' => $session->id,
            'subject' => $session->subject,
            'status' => ['value' => $session->status->value, 'label' => $session->status->label()],
            'queue' => $position === null ? null : ['position' => $position, 'ahead' => $position - 1],
            // D-C11: quem aguarda é avisado se todos os técnicos saíram; a entrada na fila não é cancelada.
            'no_technician_on_duty' => $position !== null && ! ChatAgent::onDuty()->exists(),
            'technician' => $session->technician ? ['name' => $session->technician->name] : null,
            'requester' => $session->requester_id === $viewer->id ? null : ['name' => $session->requester->name],
            'outcome' => $session->outcome ? ['value' => $session->outcome->value, 'label' => $session->outcome->label()] : null,
            'ticket_number' => $session->ticket?->ticket_number,
            'created_at' => $session->created_at->toIso8601String(),
            'accepted_at' => $session->accepted_at?->toIso8601String(),
            'cancelled_at' => $session->cancelled_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
        ];
    }
}
