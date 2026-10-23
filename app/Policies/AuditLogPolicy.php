<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * Consulta à auditoria (AUD-01, RF-19): `audit.view`. Usuários inativos não executam operações
     * protegidas (RF-02). Só leitura: não existe habilidade de criar, alterar ou excluir eventos.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission('audit.view');
    }

    /** Detalhes de um evento: quem consulta a listagem consulta cada evento. */
    public function view(User $user, AuditLog $log): bool
    {
        return $this->viewAny($user);
    }
}
