<?php

namespace App\Enums;

/**
 * Tipos de evento gravados em `ticket_histories` (RF-19). Por enquanto os da edição operacional
 * (CHA-04), da atribuição (CHA-05), da autoatribuição (CHA-06), da alteração de status (CHA-07),
 * do registro de pendência (CHA-08) e da resolução (CHA-09); os demais eventos do RF-19 entram com
 * seus próprios fluxos.
 * A retomada de uma pendência é um `StatusChanged` que também registra o fim da pausa.
 */
enum TicketEvent: string
{
    case PriorityChanged = 'priority_changed';
    case CategoryChanged = 'category_changed';
    case DepartmentTransferred = 'department_transferred';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case SelfAssigned = 'self_assigned';
    case StatusChanged = 'status_changed';
    case PendingRegistered = 'pending_registered';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::PriorityChanged => 'Prioridade alterada',
            self::CategoryChanged => 'Categoria alterada',
            self::DepartmentTransferred => 'Departamento transferido',
            self::Assigned => 'Técnico atribuído',
            self::Reassigned => 'Técnico reatribuído',
            self::SelfAssigned => 'Chamado assumido',
            self::StatusChanged => 'Status alterado',
            self::PendingRegistered => 'Pendência registrada',
            self::Resolved => 'Chamado resolvido',
        };
    }
}
