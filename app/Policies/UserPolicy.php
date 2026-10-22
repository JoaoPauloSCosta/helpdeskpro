<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Listagem de usuários (ADM-01, RF-02): `users.manage`. O PRD não define escopo por
     * departamento para a administração, então quem tem a permissão consulta todos os usuários.
     * Usuários inativos não executam operações protegidas (RF-02).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission('users.manage');
    }

    /** Criação de usuário (ADM-02). A listagem só oferece a ação quando a rota existir. */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasPermission('users.manage');
    }

    /**
     * Edição de usuário (ADM-03): `users.manage` e um alvo cujo papel atual o operador poderia
     * atribuir (Role::isGrantableBy()). Sem isso, um operador menos privilegiado poderia trocar o
     * e-mail de um administrador e assumir a conta pela redefinição de senha.
     */
    public function update(User $operator, User $target): bool
    {
        return $this->viewAny($operator)
            && ($target->role === null || $target->role->isGrantableBy($operator));
    }

    /**
     * Papel e situação da conta (ADM-03). O operador não altera os da própria conta, para não
     * remover o próprio acesso administrativo nem se inativar por engano. O PRD não define essa
     * política; é uma decisão conservadora, registrada como pendente.
     */
    public function changeAccess(User $operator, User $target): bool
    {
        return $operator->isNot($target) && $this->update($operator, $target);
    }
}
