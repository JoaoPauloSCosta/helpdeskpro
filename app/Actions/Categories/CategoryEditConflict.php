<?php

namespace App\Actions\Categories;

use RuntimeException;

/**
 * A categoria foi alterada por outra pessoa depois que o formulário de edição foi aberto (ADM-09).
 */
class CategoryEditConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A categoria foi alterada por outra pessoa.');
    }
}
