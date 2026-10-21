<?php

namespace App\Actions\Tickets;

use RuntimeException;

/**
 * O status do chamado mudou depois que o usuário abriu a alteração de status (CHA-07).
 */
class TicketStatusConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('O status do chamado foi alterado por outra pessoa.');
    }
}
