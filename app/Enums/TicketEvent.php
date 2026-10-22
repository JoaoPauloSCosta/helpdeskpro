<?php

namespace App\Enums;

/**
 * Tipos de evento gravados em `ticket_histories` (RF-19). Por enquanto os da abertura (CHA-02), da
 * edição operacional (CHA-04), da atribuição (CHA-05), da autoatribuição (CHA-06), da alteração de
 * status (CHA-07), do registro de pendência (CHA-08), da resolução (CHA-09), da reabertura (CHA-10),
 * do encerramento (CHA-11) e da inclusão e edição de comentários (RF-11, CHA-12); os demais eventos
 * do RF-19 (administração, políticas e metas de SLA, violações) entram com seus próprios fluxos.
 * A retomada de uma pendência é um `StatusChanged` que também registra o fim da pausa.
 */
enum TicketEvent: string
{
    case Created = 'created';
    case PriorityChanged = 'priority_changed';
    case CategoryChanged = 'category_changed';
    case DepartmentTransferred = 'department_transferred';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case SelfAssigned = 'self_assigned';
    case StatusChanged = 'status_changed';
    case PendingRegistered = 'pending_registered';
    case Resolved = 'resolved';
    case Reopened = 'reopened';
    case Closed = 'closed';
    case CommentAdded = 'comment_added';
    case CommentEdited = 'comment_edited';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Chamado aberto',
            self::PriorityChanged => 'Prioridade alterada',
            self::CategoryChanged => 'Categoria alterada',
            self::DepartmentTransferred => 'Departamento transferido',
            self::Assigned => 'Técnico atribuído',
            self::Reassigned => 'Técnico reatribuído',
            self::SelfAssigned => 'Chamado assumido',
            self::StatusChanged => 'Status alterado',
            self::PendingRegistered => 'Pendência registrada',
            self::Resolved => 'Chamado resolvido',
            self::Reopened => 'Chamado reaberto',
            self::Closed => 'Chamado encerrado',
            self::CommentAdded => 'Comentário adicionado',
            self::CommentEdited => 'Comentário editado',
        };
    }
}
