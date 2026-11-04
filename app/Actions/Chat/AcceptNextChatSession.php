<?php

namespace App\Actions\Chat;

use App\Enums\ChatEvent;
use App\Enums\ChatSessionStatus;
use App\Models\ChatSession;
use App\Models\User;
use App\Support\ChatBroadcast;
use App\Support\ChatTransaction;

/**
 * "Atender próximo" (RF-29, RN-CHAT-01, RN-CHAT-05). Numa transação: bloqueia a linha do técnico e confere a
 * capacidade (LockAgentForAssignment), bloqueia a sessão aguardando mais antiga e a associa ao técnico. Ordem
 * de bloqueio: técnico, depois sessão (docs/chat/02-system-design-chat.md, 4.1). Dois técnicos nunca recebem
 * a mesma sessão: o segundo espera o bloqueio e lê a fila já atualizada.
 */
class AcceptNextChatSession
{
    /** Releituras da fila se a sessão bloqueada já não estiver aguardando. */
    private const ATTEMPTS = 3;

    public function __construct(private LockAgentForAssignment $lockAgent) {}

    /**
     * @throws ChatAgentUnavailable
     * @throws ChatCapacityReached
     * @throws ChatQueueEmpty
     * @throws ChatSessionConflict
     */
    public function __invoke(User $technician): ChatSession
    {
        $session = ChatTransaction::run(function () use ($technician) {
            ($this->lockAgent)($technician);

            for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
                // Leitura com bloqueio: espera quem está assumindo e relê a fila já atualizada.
                $session = ChatSession::where('status', ChatSessionStatus::Waiting)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first() ?? throw new ChatQueueEmpty;

                if ($session->status !== ChatSessionStatus::Waiting) {
                    continue;
                }

                $now = now();

                $session->forceFill([
                    'status' => ChatSessionStatus::Active,
                    'technician_id' => $technician->id,
                    'accepted_at' => $now,
                ])->save();

                $session->events()->forceCreate([
                    'user_id' => $technician->id,
                    'event_type' => ChatEvent::Accepted,
                    'data' => ['technician_id' => $technician->id],
                    'created_at' => $now,
                ]);

                return $session;
            }

            throw new ChatSessionConflict('A fila mudou. Atualize e tente novamente.');
        });

        ChatBroadcast::session($session, ChatEvent::Accepted);
        ChatBroadcast::queue();

        return $session;
    }
}
