<?php

namespace App\Policies;

use App\Models\User;

class PermissionPolicy
{
    /**
     * Catálogo de permissões (PER-05, RF-03): somente consulta, com `roles.manage`, a mesma
     * permissão da matriz (PER-04). O catálogo (PRD 7.3) não tem permissão própria de leitura.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission('roles.manage');
    }
}
