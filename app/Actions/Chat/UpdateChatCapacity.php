<?php

namespace App\Actions\Chat;

use App\Enums\AdminEvent;
use App\Models\ChatAgent;
use App\Models\User;
use App\Support\ChatTransaction;

/**
 * Capacidade de um técnico no chat (RF-28, RF-36). Grava sob o bloqueio da linha do técnico, o mesmo do
 * guarda de capacidade, e audita a mudança em `audit_logs` (auditável = o técnico). Não encerra nem
 * transfere sessões: com o limite abaixo das sessões ativas, o técnico só fica ocupado.
 */
class UpdateChatCapacity
{
    /** @return bool Se houve alteração. */
    public function __invoke(User $operator, User $technician, int $maxSessions): bool
    {
        return ChatTransaction::run(function () use ($operator, $technician, $maxSessions) {
            $agent = ChatAgent::lockFor($technician);
            $previous = $agent->max_sessions;

            if ($previous === $maxSessions) {
                return false;
            }

            $agent->forceFill(['max_sessions' => $maxSessions])->save();

            $technician->auditLogs()->forceCreate([
                'user_id' => $operator->id,
                'event_type' => AdminEvent::ChatCapacityUpdated,
                'old_values' => ['chat_max_sessions' => $previous],
                'new_values' => ['chat_max_sessions' => $maxSessions],
                'created_at' => now(),
            ]);

            return true;
        });
    }
}
