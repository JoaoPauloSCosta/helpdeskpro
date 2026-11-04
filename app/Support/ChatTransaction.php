<?php

namespace App\Support;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Transação das operações do chat em READ COMMITTED (docs/chat/02-system-design-chat.md, seção 4).
 *
 * No REPEATABLE READ padrão do MariaDB, descobrimos pelos testes de tests/Concurrency:
 * - uma leitura comum feita antes de um bloqueio congela a "foto" da transação, e a contagem de sessões
 *   depois do bloqueio não via o que outros gravaram (8 de 8 passando com limite 2);
 * - as leituras com bloqueio trancam intervalos do índice e geravam deadlocks entre técnicos.
 * Em READ COMMITTED, cada leitura vê o último dado gravado e quase não há trava de intervalo. Os bloqueios
 * de linha continuam garantindo a exclusão. Só as transações do chat mudam; o resto da aplicação segue no padrão.
 *
 * O nível vale para a próxima transação, por isso é definido a cada tentativa (a retentativa do Laravel abriria
 * a nova tentativa no nível padrão). Dentro de uma transação já aberta (como nos testes), só executa.
 */
class ChatTransaction
{
    use DetectsConcurrencyErrors;

    public static function run(Closure $callback, int $attempts = 3): mixed
    {
        return (new self)->attempt($callback, $attempts);
    }

    private function attempt(Closure $callback, int $attempts): mixed
    {
        $connection = DB::connection();

        for ($attempt = 1; ; $attempt++) {
            if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }

            try {
                return $connection->transaction($callback);
            } catch (Throwable $e) {
                if ($attempt >= $attempts || $connection->transactionLevel() > 0 || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }
            }
        }
    }
}
