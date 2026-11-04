<?php

namespace App\Actions\Chat;

use App\Enums\ChatEvent;
use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatSession;
use App\Models\User;
use App\Support\ChatBroadcast;
use App\Support\ChatTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Transferência de uma sessão em atendimento para outro técnico (RF-35, D-C4). Passa direto, sem aceite do
 * destino, que precisa estar de plantão e abaixo da capacidade (a mesma garantia de "Atender próximo").
 *
 * Ordem de bloqueio (docs/chat/02-system-design-chat.md, 4.2): as linhas dos dois técnicos em ordem
 * crescente de id, depois a sessão. Quem transfere pode ser o gestor, que não é o técnico atual.
 * Conversa e mensagens ficam como estão; o técnico anterior deixa de ter acesso à sessão.
 */
class TransferChatSession
{
    public function __construct(private LockAgentForAssignment $lockAgent) {}

    /**
     * @throws AuthorizationException
     * @throws ChatSessionConflict
     */
    public function __invoke(User $actor, ChatSession $session, int $targetId): ChatSession
    {
        $target = User::find($targetId) ?? throw new ChatSessionConflict('Escolha um técnico válido para receber o atendimento.');
        $fromId = $session->technician_id;

        $transferred = ChatTransaction::run(function () use ($actor, $session, $target, $fromId) {
            // Linhas dos técnicos primeiro, em ordem crescente; só então a sessão.
            foreach (collect([$fromId, $target->id])->filter()->unique()->sort() as $id) {
                if ($id === $target->id) {
                    try {
                        ($this->lockAgent)($target);
                    } catch (ChatAgentUnavailable) {
                        throw new ChatSessionConflict('O técnico escolhido não está disponível.');
                    } catch (ChatCapacityReached) {
                        throw new ChatSessionConflict('O técnico escolhido atingiu o limite de atendimentos.');
                    }
                } else {
                    ChatAgent::where('user_id', $id)->lockForUpdate()->first();
                }
            }

            $current = ChatSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status !== ChatSessionStatus::Active || $current->technician_id !== $fromId) {
                throw new ChatSessionConflict('Este atendimento mudou. Atualize e tente novamente.');
            }

            Gate::forUser($actor)->authorize('transfer', $current);

            if ($target->id === $current->technician_id) {
                throw new ChatSessionConflict('O atendimento já está com este técnico.');
            }

            $current->forceFill(['technician_id' => $target->id])->save();
            $current->events()->forceCreate([
                'user_id' => $actor->id,
                'event_type' => ChatEvent::Transferred,
                'data' => ['from_technician_id' => $fromId, 'to_technician_id' => $target->id],
                'created_at' => now(),
            ]);

            return $current->setRelation('technician', $target);
        });

        ChatBroadcast::session($transferred, ChatEvent::Transferred, [$fromId, $actor->id]);

        return $transferred;
    }
}
