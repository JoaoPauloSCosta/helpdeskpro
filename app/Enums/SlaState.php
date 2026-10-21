<?php

namespace App\Enums;

/**
 * Situação do SLA de resolução exibida na listagem.
 *
 * "Em risco" ainda não existe: o limite (80% sugerido no design-system.md) não foi confirmado.
 * O estado é derivado de `resolution_due_at`, que será mantido pelo motor de SLA (Etapa 7);
 * enquanto ele não existir, os chamados ficam como `None` (sem prazo definido).
 */
enum SlaState: string
{
    case OnTrack = 'on_track';
    case Overdue = 'overdue';
    case Paused = 'paused';
    case Met = 'met';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::OnTrack => 'No prazo',
            self::Overdue => 'Vencido',
            self::Paused => 'Pausado',
            self::Met => 'Cumprido',
            self::None => 'Sem prazo definido',
        };
    }
}
