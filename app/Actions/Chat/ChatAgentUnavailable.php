<?php

namespace App\Actions\Chat;

use RuntimeException;

/** O técnico não está disponível para novos atendimentos: chave manual desligada, sem presença ou sem permissão. */
class ChatAgentUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Você não está disponível para novos atendimentos.');
    }
}
