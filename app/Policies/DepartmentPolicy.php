<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    /**
     * Listagem de departamentos (ADM-04, RF-02): `departments.manage`. O PRD não define escopo por
     * departamento para a administração; quem tem a permissão consulta todos, ativos e inativos.
     * Usuários inativos não executam operações protegidas (RF-02).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission('departments.manage');
    }

    /** Criação de departamento (ADM-05). A listagem só oferece a ação quando a rota existir. */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasPermission('departments.manage');
    }

    /**
     * Edição de departamento (ADM-06), incluindo a situação. O catálogo (PRD 7.3) tem uma única
     * permissão para departamentos, então dados e situação não são autorizados separadamente.
     */
    public function update(User $user, Department $department): bool
    {
        return $user->is_active && $user->hasPermission('departments.manage');
    }
}
