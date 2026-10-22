<?php

namespace App\Actions\Departments;

use RuntimeException;

/**
 * O departamento foi alterado por outra pessoa depois que o formulário de edição foi aberto (ADM-06).
 */
class DepartmentEditConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('O departamento foi alterado por outra pessoa.');
    }
}
