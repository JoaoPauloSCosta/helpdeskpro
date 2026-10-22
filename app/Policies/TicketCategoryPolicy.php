<?php

namespace App\Policies;

use App\Models\TicketCategory;
use App\Models\User;

class TicketCategoryPolicy
{
    /**
     * Listagem de categorias (ADM-07, RF-04): `categories.manage`. Quem tem a permissão consulta
     * todas, ativas e inativas. Usuários inativos não executam operações protegidas (RF-02).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission('categories.manage');
    }

    /** Criação de categoria (ADM-08). A listagem só oferece a ação quando a rota existir. */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasPermission('categories.manage');
    }

    /**
     * Edição de categoria (ADM-09), incluindo a situação. O catálogo (PRD 7.3) tem uma única
     * permissão para categorias, então dados e situação não são autorizados separadamente.
     */
    public function update(User $user, TicketCategory $category): bool
    {
        return $user->is_active && $user->hasPermission('categories.manage');
    }
}
