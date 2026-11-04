<?php

namespace App\Enums;

/**
 * Disponibilidade efetiva do técnico no chat (RF-28, RN-CHAT-03). Calculada no servidor a partir da chave
 * manual, da presença, da situação do usuário, da permissão e da capacidade; nunca gravada.
 */
enum ChatAvailability: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponível',
            self::Busy => 'Ocupado',
            self::Unavailable => 'Indisponível',
        };
    }
}
