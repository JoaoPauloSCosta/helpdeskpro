<?php

use Illuminate\Support\Facades\DB;

/**
 * Conexão dos testes de concorrência: o MariaDB real (o SQLite dos testes comuns ignora `lockForUpdate`),
 * num banco próprio. Usada pelo teste e pelos processos paralelos (worker.php).
 *
 * Proteção: o banco precisa terminar em `_testing`, porque o teste apaga e recria todas as tabelas.
 */
$database = env('CHAT_CONCURRENCY_DATABASE', 'helpdesk_testing');

if (! str_ends_with($database, '_testing')) {
    throw new RuntimeException("Banco de concorrência recusado: {$database}. Use um banco terminado em _testing.");
}

config([
    'database.connections.concurrency' => [...config('database.connections.mariadb'), 'database' => $database],
    'database.default' => 'concurrency',
]);

DB::purge('concurrency');
