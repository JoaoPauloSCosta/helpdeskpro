<?php

namespace App\Enums;

/**
 * Tipos de evento gravados em `ticket_histories` (RF-19). Por enquanto os da edição operacional
 * (CHA-04), da atribuição (CHA-05) e da autoatribuição (CHA-06); os demais eventos do RF-19 entram com seus próprios fluxos.
 */
enum TicketEvent: string
{
    case PriorityChanged = 'priority_changed';
    case CategoryChanged = 'category_changed';
    case DepartmentTransferred = 'department_transferred';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case SelfAssigned = 'self_assigned';

    public function label(): string
    {
        return match ($this) {
            self::PriorityChanged => 'Prioridade alterada',
            self::CategoryChanged => 'Categoria alterada',
            self::DepartmentTransferred => 'Departamento transferido',
            self::Assigned => 'Técnico atribuído',
            self::Reassigned => 'Técnico reatribuído',
            self::SelfAssigned => 'Chamado assumido',
        };
    }
}
