<?php

namespace App\Actions\Chat;

use App\Enums\ChatSessionStatus;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Policies\ChatSessionPolicy;
use App\Support\ChatBroadcast;
use App\Support\ChatTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Envio de mensagem (RF-30, RN-CHAT-07). Com a sessão bloqueada (o encerramento não passa no meio): grava e
 * só depois do commit avisa em tempo real. `client_id` vem do navegador e torna o reenvio idempotente: a
 * mesma mensagem enviada de novo (falha de rede, clique repetido) devolve a já gravada.
 *
 * Primeira resposta: a primeira mensagem do técnico grava `first_response_at`, usado no SLA do chamado
 * gerado no encerramento (D-C12).
 */
class SendChatMessage
{
    /**
     * @return array{0: ChatMessage, 1: bool} A mensagem e se ela acabou de ser gravada.
     *
     * @throws AuthorizationException
     * @throws ChatSessionConflict
     */
    public function __invoke(User $author, ChatSession $session, string $body, string $clientId): array
    {
        [$message, $created, $current] = ChatTransaction::run(function () use ($author, $session, $body, $clientId) {
            $current = ChatSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (! ChatSessionPolicy::isParticipant($author, $current)) {
                throw new AuthorizationException;
            }

            // Reenvio de uma mensagem já gravada: devolve a mesma, mesmo que a sessão tenha sido encerrada depois.
            if ($existing = $current->messages()->where('client_id', $clientId)->first()) {
                if ($existing->user_id !== $author->id) {
                    throw new ChatSessionConflict('Identificador de mensagem já utilizado. Tente novamente.');
                }

                return [$existing, false, $current];
            }

            if ($current->status !== ChatSessionStatus::Active) {
                throw new ChatSessionConflict('Este atendimento não está em andamento. A mensagem não foi enviada.');
            }

            Gate::forUser($author)->authorize('sendMessage', $current);

            $message = $current->messages()->forceCreate([
                'user_id' => $author->id,
                'body' => $body,
                'client_id' => $clientId,
                'created_at' => now(),
            ]);

            if ($author->id === $current->technician_id && $current->first_response_at === null) {
                $current->forceFill(['first_response_at' => $message->created_at])->save();
            }

            return [$message, true, $current];
        });

        if ($created) {
            ChatBroadcast::message($message, $current);
        }

        return [$message, $created];
    }
}
