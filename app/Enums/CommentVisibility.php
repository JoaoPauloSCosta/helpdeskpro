<?php

namespace App\Enums;

/**
 * Tipos de mensagem do RF-11. Notas internas nunca são exibidas ao solicitante.
 */
enum CommentVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
}
