<?php

namespace App\Enums;

/**
 * Estados da sessão de chat (docs/chat/01-decisoes-e-contratos.md, seção 3). Conexão (conectando,
 * reconectando) é estado da interface e não entra aqui. A transferência mantém `Active`.
 */
enum ChatSessionStatus: string
{
    case Waiting = 'waiting';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Aguardando',
            self::Active => 'Em atendimento',
            self::Cancelled => 'Cancelada',
            self::Ended => 'Encerrada',
        };
    }

    /** Transições permitidas a partir deste estado; as demais são recusadas. @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Waiting => [self::Active, self::Cancelled],
            self::Active => [self::Ended],
            self::Cancelled, self::Ended => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /** Sessão aberta: ocupa `active_requester_id`, o que limita o solicitante a uma por vez (RF-26). */
    public function isOpen(): bool
    {
        return $this === self::Waiting || $this === self::Active;
    }
}
