<?php

namespace App\Enums;

/**
 * Situação do SLA de resolução exibida na listagem.
 *
 * "Em risco" (D7): o chamado ainda está no prazo, mas já consumiu AT_RISK_PERCENT% da meta
 * (limite de 80% do design-system.md 3.3, confirmado). Vale para a primeira resposta e para a resolução.
 * O estado é derivado do prazo vigente (`resolution_due_at`), mantido pelo motor de SLA; sem prazo
 * (sem política na prioridade), o chamado fica como `None`. É a situação atual, não o histórico:
 * as violações já ocorridas ficam em `*_breached_at`.
 */
enum SlaState: string
{
    /** Parte da meta consumida a partir da qual um chamado ainda no prazo passa a "Em risco". */
    public const AT_RISK_PERCENT = 80;

    case OnTrack = 'on_track';
    case AtRisk = 'at_risk';
    case Overdue = 'overdue';
    case Paused = 'paused';
    case Met = 'met';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::OnTrack => 'No prazo',
            self::AtRisk => 'Em risco',
            self::Overdue => 'Vencido',
            self::Paused => 'Pausado',
            self::Met => 'Cumprido',
            self::None => 'Sem prazo definido',
        };
    }
}
