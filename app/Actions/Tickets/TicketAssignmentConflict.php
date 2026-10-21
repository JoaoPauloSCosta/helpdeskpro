<?php

namespace App\Actions\Tickets;

use RuntimeException;

/**
 * O responsável pelo chamado mudou depois que o modal de atribuição foi aberto (CHA-05).
 */
class TicketAssignmentConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('O responsável pelo chamado foi alterado por outra pessoa.');
    }
}
