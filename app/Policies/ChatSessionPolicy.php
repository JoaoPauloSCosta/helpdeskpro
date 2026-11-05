<?php

namespace App\Policies;

use App\Enums\ChatSessionStatus;
use App\Models\ChatSession;
use App\Models\User;

/**
 * Acesso às sessões de chat (docs/chat/02-system-design-chat.md, seção 10). Conhecer o id não basta:
 * o usuário precisa participar da sessão ou ter uma permissão de consulta ampla. As actions conferem de
 * novo sobre a sessão bloqueada. Usuários inativos não fazem nada (RF-02).
 */
class ChatSessionPolicy
{
    /** Solicitante, técnico responsável atual ou quem consulta o histórico de todos (`chat.view_history`). */
    public function view(User $user, ChatSession $session): bool
    {
        return $user->is_active
            && (self::isParticipant($user, $session) || $user->hasPermission('chat.view_history'));
    }

    /** Só os participantes de uma sessão em atendimento (RF-30); sessão encerrada não recebe mensagens. */
    public function sendMessage(User $user, ChatSession $session): bool
    {
        return $user->is_active
            && $session->status === ChatSessionStatus::Active
            && $user->hasPermission('chat.send_message')
            && self::isParticipant($user, $session);
    }

    /** O próprio solicitante, enquanto aguarda (RF-32). Não encerra uma sessão já assumida. */
    public function cancel(User $user, ChatSession $session): bool
    {
        return $user->is_active
            && $session->status === ChatSessionStatus::Waiting
            && $session->requester_id === $user->id;
    }

    /**
     * Transferência (RF-35, D-C4): o técnico responsável passa a própria sessão; quem enxerga todas as
     * sessões (`chat.view_history`, o Gestor) transfere qualquer uma. O destino é validado na action.
     */
    public function transfer(User $user, ChatSession $session): bool
    {
        return $user->is_active
            && $session->status === ChatSessionStatus::Active
            && $user->hasPermission('chat.transfer')
            && ($session->technician_id === $user->id || $user->hasPermission('chat.view_history'));
    }

    /** Encerramento (RF-34): só o técnico responsável. */
    public function close(User $user, ChatSession $session): bool
    {
        return $user->is_active
            && $session->status === ChatSessionStatus::Active
            && $user->hasPermission('chat.close')
            && $session->technician_id === $user->id;
    }

    public static function isParticipant(User $user, ChatSession $session): bool
    {
        return $session->requester_id === $user->id
            || ($session->technician_id !== null && $session->technician_id === $user->id);
    }
}
