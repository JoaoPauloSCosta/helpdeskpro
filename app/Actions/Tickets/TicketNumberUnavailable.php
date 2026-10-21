<?php

namespace App\Actions\Tickets;

use RuntimeException;

/**
 * Não foi possível obter um identificador único dentro do limite de tentativas (RF-06, regra 9).
 */
class TicketNumberUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Não foi possível gerar um identificador único para o chamado.');
    }
}
