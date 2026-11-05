<?php

namespace App\Enums;

/** Resultado informado no encerramento (RF-34): define o status do chamado gerado. */
enum ChatOutcome: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return match ($this) {
            self::Resolved => 'Resolvido',
            self::Unresolved => 'Não resolvido',
        };
    }
}
