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
     * Associação de permissões na matriz (PER-04). Além de `roles.manage`:
     *
     * - o operador não altera o próprio papel, para não se conceder poderes nem remover o próprio
     *   acesso por engano (mesma decisão do ADM-03 para o papel da própria conta);
     * - o papel precisa ser um que o operador poderia atribuir (Role::isGrantableBy()): quem não
     *   possui as permissões administrativas de um papel não as retira dele.
     *
     * Cada permissão administrativa adicionada ou removida também precisa estar entre as do
     * operador (verificado em UpdateRolePermissions). A consulta da matriz só exige `viewAny`.
     */
    public function managePermissions(User $user, Role $role): bool
    {
        return $this->viewAny($user)
            && $role->id !== $user->role_id
            && $role->isGrantableBy($user);
    }
}
