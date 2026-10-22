<?php

namespace App\Actions\Users;

use RuntimeException;

/**
 * O cadastro foi alterado por outra pessoa depois que o formulário de edição foi aberto (ADM-03).
 */
class UserEditConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('O usuário foi alterado por outra pessoa.');
    }
}
