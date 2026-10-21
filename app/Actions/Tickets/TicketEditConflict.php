<?php

namespace App\Actions\Tickets;

use RuntimeException;

/**
 * Prioridade, categoria ou departamento foram alterados por outra pessoa depois que o
 * formulário de edição foi aberto (CHA-04).
 */
class TicketEditConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('O chamado foi alterado por outra pessoa.');
    }
}
