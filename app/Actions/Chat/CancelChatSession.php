<?php

namespace App\Actions\Chat;

use App\Enums\ChatEvent;
use App\Enums\ChatSessionStatus;
use App\Models\ChatSession;
use App\Models\User;
use App\Support\ChatBroadcast;
use App\Support\ChatTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Cancelamento enquanto aguarda (RF-32). Com a sessão bloqueada: cancelar e "Atender próximo" ao mesmo tempo
 * têm um só vencedor. Repetir o cancelamento devolve a sessão já cancelada. Não encerra sessão assumida.
 */
class CancelChatSession
{
    /**
     * @throws AuthorizationException
     * @throws ChatSessionConflict
     */
    public function __invoke(User $requester, ChatSession $session): ChatSession
    {
        $changed = false;

        $cancelled = ChatTransaction::run(function () use ($requester, $session, &$changed) {
            $current = ChatSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($current->requester_id !== $requester->id) {
                throw new AuthorizationException;
            }

            if ($current->status === ChatSessionStatus::Cancelled) {
                return $current;
            }

            if ($current->status !== ChatSessionStatus::Waiting) {
                throw new ChatSessionConflict('O atendimento já foi iniciado e não pode mais ser cancelado.');
            }

            Gate::forUser($requester)->authorize('cancel', $current);

            $now = now();

            $current->forceFill([
                'status' => ChatSessionStatus::Cancelled,
                'active_requester_id' => null,
                'cancelled_at' => $now,
            ])->save();

            $current->events()->forceCreate([
                'user_id' => $requester->id,
                'event_type' => ChatEvent::Cancelled,
                'data' => null,
                'created_at' => $now,
            ]);

            $changed = true;

            return $current;
        });

        if ($changed) {
            ChatBroadcast::queue();
            ChatBroadcast::session($cancelled, ChatEvent::Cancelled);
        }

        return $cancelled;
    }
}
