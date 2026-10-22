<?php

namespace App\Policies;

use App\Models\SlaPolicy;
use App\Models\User;

class SlaPolicyPolicy
{
    /**
     * Listagem de políticas de SLA (SLA-01, RF-15): `sla.manage`. O catálogo (PRD 7.3) não tem
     * permissão própria de leitura. Usuários inativos não executam operações protegidas (RF-02).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission('sla.manage');
    }

    /** Detalhes de uma política (SLA-04): quem consulta a listagem consulta cada política. */
    public function view(User $user, SlaPolicy $policy): bool
    {
        return $user->is_active && $user->hasPermission('sla.manage');
    }

    /**
     * Histórico de alterações da política nos detalhes: a consulta à auditoria exige `audit.view`
     * (RF-19), além do acesso à política.
     */
    public function viewHistory(User $user, SlaPolicy $policy): bool
    {
        return $this->view($user, $policy) && $user->hasPermission('audit.view');
    }

    /** Criação (SLA-02): a mesma `sla.manage`, a única permissão de SLA do catálogo (PRD 7.3). */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasPermission('sla.manage');
    }

    /** Edição de metas e situação (SLA-03), com a mesma permissão; metas e situação não são separadas. */
    public function update(User $user, SlaPolicy $policy): bool
    {
        return $user->is_active && $user->hasPermission('sla.manage');
    }
}
