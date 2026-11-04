<?php

namespace App\Actions\Chat;

use App\Enums\ChatEvent;
use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatSession;
use App\Models\User;
use App\Support\ChatBroadcast;
use App\Support\ChatTransaction;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Pedido de atendimento (RF-26, RF-27). Solicitante e departamento vêm do usuário autenticado; a sessão entra
 * no fim da fila (ordem do `id`). Idempotente: quem já tem uma sessão aberta recebe a mesma, e o índice único
 * `active_requester_id` impede a segunda mesmo com pedidos simultâneos.
 */
class RequestChatSession
{
    /**
     * @return array{0: ChatSession, 1: bool} A sessão e se ela acabou de ser criada.
     *
     * @throws NoChatTechnicianAvailable
     */
    public function __invoke(User $requester, string $subject): array
    {
        if ($open = self::openSessionOf($requester)) {
            return [$open, false];
        }

        // RN-CHAT-06: sem técnico de plantão, ninguém entra numa fila que não anda.
        if (! ChatAgent::onDuty()->exists()) {
            throw new NoChatTechnicianAvailable;
        }

        try {
            $session = ChatTransaction::run(function () use ($requester, $subject) {
                $session = ChatSession::forceCreate([
                    'requester_id' => $requester->id,
                    'active_requester_id' => $requester->id,
                    'department_id' => $requester->department_id,
                    'subject' => $subject,
                    'status' => ChatSessionStatus::Waiting,
                ]);

                $session->events()->forceCreate([
                    'user_id' => $requester->id,
                    'event_type' => ChatEvent::Requested,
                    'data' => ['department_id' => $requester->department_id],
                    'created_at' => $session->created_at,
                ]);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Outro pedido do mesmo solicitante foi gravado primeiro.
            return [self::openSessionOf($requester) ?? throw new ChatSessionConflict('Tente novamente.'), false];
        }

        ChatBroadcast::queue();

        return [$session, true];
    }

    public static function openSessionOf(User $requester): ?ChatSession
    {
        return ChatSession::firstWhere('active_requester_id', $requester->id);
    }
}
