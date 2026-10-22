<?php

namespace App\Actions\Roles;

use RuntimeException;

/**
 * O papel foi alterado por outra pessoa depois que o formulário de edição foi aberto (PER-03).
 */
class RoleEditConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('O papel foi alterado por outra pessoa.');
    }
}
