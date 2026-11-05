<?php

namespace App\Actions\Chat;

use RuntimeException;

/** Nenhum técnico de plantão (RN-CHAT-06): o solicitante recebe a alternativa de abrir um chamado tradicional. */
class NoChatTechnicianAvailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Não temos técnicos disponíveis para atendimento via chat neste momento. Você pode registrar um chamado para que sua solicitação seja atendida.');
    }
}
