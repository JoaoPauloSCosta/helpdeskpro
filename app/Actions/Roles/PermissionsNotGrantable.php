<?php

namespace App\Actions\Roles;

use RuntimeException;

/**
 * A alteração adiciona ou remove permissões administrativas que o operador não possui (PER-04).
 */
class PermissionsNotGrantable extends RuntimeException
{
    /**
     * @param  list<string>  $names  Nomes legíveis das permissões recusadas.
     */
    public function __construct(public readonly array $names)
    {
        parent::__construct('Permissões administrativas fora do alcance do operador.');
    }
}
