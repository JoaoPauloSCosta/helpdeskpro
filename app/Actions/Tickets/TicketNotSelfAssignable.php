<?php

namespace App\Actions\Tickets;

use RuntimeException;

/**
 * O chamado deixou de estar `Aberto` e sem responsável antes da gravação, por exemplo porque
 * outro técnico o assumiu primeiro (CHA-06, RF-09 regra 9).
 */
class TicketNotSelfAssignable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('O chamado não está mais disponível para autoatribuição.');
    }
}
