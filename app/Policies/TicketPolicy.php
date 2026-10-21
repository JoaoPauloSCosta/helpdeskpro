<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Acesso à listagem. O que cada usuário enxerga é definido por Ticket::scopeVisibleTo().
     * Usuários inativos não executam operações protegidas (RF-02).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    /**
     * Abertura de chamado (RF-05). O PRD não prevê uma permissão específica: abrir é uma ação
     * própria do solicitante (RF-03). Usuários sem papel ainda não foram liberados pela
     * administração e, como em todo o RBAC, não recebem acesso por padrão.
     * O departamento ativo é exigido na validação, para a tela explicar o motivo.
     */
    public function create(User $user): bool
    {
        return $user->is_active && $user->role_id !== null;
    }

    /**
     * Consulta dos detalhes (CHA-03). Usa o mesmo escopo da listagem, para que um chamado
     * só possa ser aberto por quem o enxerga em CHA-01. Conhecer o identificador não basta (RF-06).
     */
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->is_active && Ticket::visibleTo($user)->whereKey($ticket->getKey())->exists();
    }

    /**
     * Comentário público (RF-11): quem tem acesso ao chamado. O PRD não define uma permissão
     * para isso; como na abertura, usuários sem papel não recebem a ação. Chamados encerrados
     * representam o término definitivo do atendimento (RF-14) e não recebem novas mensagens.
     */
    public function comment(User $user, Ticket $ticket): bool
    {
        return $user->role_id !== null
            && $ticket->status !== TicketStatus::Closed
            && $this->view($user, $ticket);
    }

    /**
     * Edição operacional (CHA-04): acesso ao chamado e ao menos um atributo editável.
     * Visibilidade global não basta (RN-03). Chamados encerrados não são mais alterados (RF-14).
     */
    public function update(User $user, Ticket $ticket): bool
    {
        return ($this->changePriority($user, $ticket)
            || $this->changeCategory($user, $ticket)
            || $this->transferDepartment($user, $ticket));
    }

    /** RF-18 e RF-16. */
    public function changePriority(User $user, Ticket $ticket): bool
    {
        return $this->canEditAttribute($user, $ticket, 'tickets.change_priority');
    }

    /** RF-18. */
    public function changeCategory(User $user, Ticket $ticket): bool
    {
        return $this->canEditAttribute($user, $ticket, 'tickets.change_category');
    }

    /** RF-18: o técnico não transfere departamentos por padrão (sem a permissão na matriz). */
    public function transferDepartment(User $user, Ticket $ticket): bool
    {
        return $this->canEditAttribute($user, $ticket, 'tickets.transfer_department');
    }

    /** Atribuição manual (RF-08): só para chamados ainda sem responsável. */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $ticket->assigned_to === null
            && $this->canEditAttribute($user, $ticket, 'tickets.assign');
    }

    /** Reatribuição (RF-08): troca de um responsável existente. Permissão distinta de `assign`. */
    public function reassign(User $user, Ticket $ticket): bool
    {
        return $ticket->assigned_to !== null
            && $this->canEditAttribute($user, $ticket, 'tickets.reassign');
    }

    /**
     * Autoatribuição (RF-09): técnico ativo e elegível, chamado `Aberto` e sem responsável.
     * Distinta de `assign`/`reassign` (RF-08). A action confere de novo sobre o estado bloqueado.
     */
    public function selfAssign(User $user, Ticket $ticket): bool
    {
        return self::isSelfAssignable($ticket)
            && $user->isEligibleAssignee()
            && $this->view($user, $ticket);
    }

    /** Condição do chamado para a autoatribuição, também usada na listagem sem consultas extras. */
    public static function isSelfAssignable(Ticket $ticket): bool
    {
        return $ticket->status === TicketStatus::Open && $ticket->assigned_to === null;
    }

    /**
     * Alteração de status (CHA-07, RF-10): `tickets.change_status`, acesso ao chamado e uma
     * transição executável no estado atual; com `$target`, aquela transição específica.
     * Visibilidade global não basta (RN-03). A action confere de novo sobre o estado bloqueado.
     */
    public function changeStatus(User $user, Ticket $ticket, ?TicketStatus $target = null): bool
    {
        $targets = self::statusChangeTargets($ticket);

        return ($target === null ? $targets !== [] : in_array($target, $targets, true))
            && $user->hasPermission('tickets.change_status')
            && $this->view($user, $ticket);
    }

    /**
     * Destinos da alteração de status para o chamado no estado atual. Aberto → Em atendimento
     * exige um responsável: sem ele, o caminho é a autoatribuição (RF-09) ou a atribuição (RF-08),
     * para que nenhum chamado fique "Em atendimento" sem quem o atenda.
     *
     * @return list<TicketStatus>
     */
    public static function statusChangeTargets(Ticket $ticket): array
    {
        if ($ticket->status === TicketStatus::Open && $ticket->assigned_to === null) {
            return [];
        }

        return $ticket->status->statusChangeTargets();
    }

    private function canEditAttribute(User $user, Ticket $ticket, string $permission): bool
    {
        return $user->hasPermission($permission)
            && $ticket->status !== TicketStatus::Closed
            && $this->view($user, $ticket);
    }

    /** Nota interna (RF-11): exige `tickets.add_internal_note` além do direito de comentar. */
    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('tickets.add_internal_note') && $this->comment($user, $ticket);
    }
}
