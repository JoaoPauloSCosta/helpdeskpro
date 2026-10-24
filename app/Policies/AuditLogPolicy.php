<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * Consulta à auditoria global (AUD-01, RF-19): `audit.view` e visão de todos os departamentos
     * (`tickets.view_all_departments`). Sem a segunda, `audit.view` vale só para o histórico dos
     * chamados que o usuário acessa (CHA-14): o técnico não lê eventos de usuários, papéis ou políticas.
     * Usuários inativos não executam operações protegidas (RF-02). Só leitura: não existe habilidade
     * de criar, alterar ou excluir eventos.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->hasPermission('audit.view')
            && $user->hasPermission('tickets.view_all_departments');
    }

    /** Detalhes de um evento: quem consulta a listagem consulta cada evento. */
    public function view(User $user, AuditLog $log): bool
    {
        return $this->viewAny($user);
    }
}
