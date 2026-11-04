<?php

namespace App\Actions\Chat;

use RuntimeException;

/** "Atender próximo" sem ninguém aguardando, inclusive quando outro técnico assumiu o último antes. */
class ChatQueueEmpty extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Não há solicitantes aguardando.');
    }
}
