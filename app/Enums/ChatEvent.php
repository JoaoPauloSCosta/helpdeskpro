<?php

namespace App\Enums;

/**
 * Tipos de evento gravados em `chat_session_events`. As mensagens ficam em `chat_messages` e não geram evento.
 */
enum ChatEvent: string
{
    case Requested = 'requested';
    case Cancelled = 'cancelled';
    case Accepted = 'accepted';
    case Transferred = 'transferred';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Atendimento solicitado',
            self::Cancelled => 'Solicitação cancelada',
            self::Accepted => 'Atendimento iniciado',
            self::Transferred => 'Atendimento transferido',
            self::Ended => 'Atendimento encerrado',
        };
    }
}
