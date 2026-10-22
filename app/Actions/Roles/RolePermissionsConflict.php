<?php

namespace App\Actions\Roles;

use RuntimeException;

/**
 * As permissões do papel foram alteradas por outra pessoa depois que a matriz foi aberta (PER-04).
 */
class RolePermissionsConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('As permissões do papel foram alteradas por outra pessoa.');
    }
}
