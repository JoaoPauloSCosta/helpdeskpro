<?php

namespace App\Actions\Chat;

use App\Enums\ChatAvailability;
use App\Models\ChatAgent;
use App\Models\User;

/**
 * Guarda de capacidade (RF-28, RN-CHAT-04, docs/chat/02-system-design-chat.md, 4). Bloqueia a linha do
 * técnico e confere a disponibilidade efetiva com as sessões contadas depois do bloqueio. Quem assume ou
 * recebe uma sessão (Atender próximo, transferência) chama isto DENTRO de um ChatTransaction::run() e grava
 * a sessão antes do commit: assim dois pedidos simultâneos nunca passam do limite. Em READ COMMITTED, a
 * contagem depois do bloqueio enxerga tudo o que já foi gravado (ver ChatTransaction e tests/Concurrency).
 */
class LockAgentForAssignment
{
    /**
     * @throws ChatAgentUnavailable
     * @throws ChatCapacityReached
     */
    public function __invoke(User $technician): ChatAgent
    {
        $agent = ChatAgent::lockFor($technician);
        $agent->setRelation('user', $technician);

        return match ($agent->availability()) {
            ChatAvailability::Available => $agent,
            ChatAvailability::Busy => throw new ChatCapacityReached,
            ChatAvailability::Unavailable => throw new ChatAgentUnavailable,
        };
    }
}
