<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    /**
     * Listagem de papéis (PER-01, RF-03): `roles.manage`, a única permissão do catálogo (PRD 7.3)
     * para papéis e permissões. A consulta não altera nada. Usuários inativos não executam
     * operações protegidas (RF-02).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission('roles.manage');
    }

    /**
     * Criação de papel (PER-02). O papel nasce sem permissões; a associação fica para a matriz
     * (PER-04), então criar não concede nenhum privilégio novo.
     */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasPermission('roles.manage');
    }

    /**
     * Edição cadastral de papel (PER-03): nome e descrição. Papéis do sistema também podem ser
     * abertos, mas mantêm o nome (regra aplicada no UpdateRoleRequest).
     */
    public function update(User $user, Role $role): bool
    {
        return $user->is_active && $user->hasPermission('roles.manage');
    }

    /**
     * Associação de permissões na matriz (PER-04). Quem tem `roles.manage` (Administrador e Super
     * administrador) altera as permissões de qualquer papel, inclusive o próprio. A única trava, contra
     * trancar o sistema, fica em UpdateRolePermissions: ninguém retira `roles.manage` do próprio papel.
     */
    public function managePermissions(User $user, Role $role): bool
    {
        return $this->viewAny($user);
    }
}
