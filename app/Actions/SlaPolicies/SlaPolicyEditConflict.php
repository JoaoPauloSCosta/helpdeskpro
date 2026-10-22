<?php

namespace App\Actions\SlaPolicies;

use RuntimeException;

/**
 * A política foi alterada por outra pessoa depois que o formulário de edição foi aberto (SLA-03).
 */
class SlaPolicyEditConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A política de SLA foi alterada por outra pessoa.');
    }
}
