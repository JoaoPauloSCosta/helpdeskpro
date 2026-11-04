<?php

namespace App\Actions\Chat;

use RuntimeException;

/** O técnico atingiu o limite de atendimentos simultâneos (RN-CHAT-04). As sessões em andamento continuam. */
class ChatCapacityReached extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Você atingiu o limite de atendimentos simultâneos.');
    }
}
