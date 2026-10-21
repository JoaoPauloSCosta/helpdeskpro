<?php

namespace App\Enums;

/**
 * Prioridades PROVISÓRIAS: o PRD ainda não define a lista (design-system.md, seção 3.2).
 * Este é o único ponto a alterar quando a lista for confirmada. A ordem dos casos vai da
 * menor para a maior prioridade (usada na ordenação).
 */
enum TicketPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baixa',
            self::Medium => 'Média',
            self::High => 'Alta',
            self::Critical => 'Crítica',
        };
    }
}
