<?php

namespace App\Policies;

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
}
