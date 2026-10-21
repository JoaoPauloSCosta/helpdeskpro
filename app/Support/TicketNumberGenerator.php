<?php

namespace App\Support;

/**
 * Identificador público dos chamados (RF-06): `HD-` + 8 caracteres aleatórios.
 * Não depende do último chamado nem da chave primária; a unicidade final é garantida
 * pelo índice UNIQUE de `tickets.ticket_number` (colisões são tratadas por OpenTicket).
 */
class TicketNumberGenerator
{
    public const PREFIX = 'HD-';

    /** Sem caracteres visualmente ambíguos (O/0, I/1). */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    public function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        // random_int() usa a fonte de aleatoriedade criptograficamente segura do sistema.
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return self::PREFIX.$code;
    }
}
