<?php

namespace App\Actions\Roles;

use RuntimeException;

/**
 * A alteração retiraria do operador o próprio acesso à gestão de papéis (PER-04).
 */
class PermissionsNotGrantable extends RuntimeException
{
    /**
     * @param  list<string>  $names  Nomes legíveis das permissões recusadas.
     */
    public function __construct(public readonly array $names)
    {
        parent::__construct('Alteração bloqueada: o operador perderia o acesso à gestão de papéis.');
    }
}
