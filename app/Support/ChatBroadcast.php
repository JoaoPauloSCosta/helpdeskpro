<?php

namespace App\Support;

use App\Enums\ChatEvent;
use App\Enums\ChatSessionStatus;
use App\Events\Chat\ChatMessageSent;
use App\Events\Chat\ChatQueueChanged;
use App\Events\Chat\ChatQueuePositionChanged;
use App\Events\Chat\ChatSessionChanged;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Único ponto de disparo dos avisos em tempo real do chat. Chamado DEPOIS do commit: o banco é a fonte de
 * verdade (RN-CHAT-07). Se o Reverb estiver fora, a falha vai para o log (sem o texto das mensagens) e a
 * requisição continua com sucesso; a tela recupera o estado ao reconectar ou pela consulta periódica.
 */
class ChatBroadcast
{
    public static function message(ChatMessage $message, ChatSession $session): void
    {
        self::send(new ChatMessageSent($message, self::participants($session)));
    }

    /** @param  list<int>  $alsoNotify  Outras pessoas envolvidas (ex.: o técnico anterior numa transferência). */
    public static function session(ChatSession $session, ChatEvent $type, array $alsoNotify = []): void
    {
        $session->loadMissing(['technician:id,name', 'ticket:id,ticket_number']);

        self::send(new ChatSessionChanged($session, $type, array_values(array_unique([...self::participants($session), ...$alsoNotify]))));
    }

    /**
     * Total para a equipe e a nova posição de cada solicitante aguardando, calculada no servidor.
     * shortcut: um aviso por solicitante aguardando; se a fila passar de algumas centenas, agrupar os envios.
     */
    public static function queue(): void
    {
        $waiting = ChatSession::where('status', ChatSessionStatus::Waiting)->orderBy('id')->get(['id', 'requester_id']);

        self::send(new ChatQueueChanged($waiting->count()));

        foreach ($waiting->values() as $index => $session) {
            self::send(new ChatQueuePositionChanged($session->requester_id, $session->id, $index + 1));
        }
    }

    /** @return list<int> */
    private static function participants(ChatSession $session): array
    {
        return array_values(array_filter([$session->requester_id, $session->technician_id]));
    }

    private static function send(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Falha ao transmitir evento do chat.', [
                'event' => class_basename($event),
                'session_id' => $event->session->id ?? $event->message->chat_session_id ?? $event->sessionId ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
