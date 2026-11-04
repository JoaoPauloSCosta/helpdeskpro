<?php

/**
 * Processo paralelo dos testes de concorrência. Espera o instante combinado (para todos começarem juntos) e
 * executa uma operação do chat pelas mesmas actions da aplicação.
 *
 * Uso: php worker.php <instante de início, em microtime> <operação> <argumentos...>
 *   assign <técnico> <sessão>  guarda de capacidade + associação da sessão (Etapa 4)
 *   accept <técnico>           "Atender próximo"
 *   request <solicitante>      pedido de atendimento
 *   cancel <solicitante> <sessão>
 *   end <técnico> <sessão> <categoria>  encerramento + chamado (Etapa 8); saída "ok:<número do chamado>"
 * Saída: "ok" ou "ok:<id da sessão>", ou o nome curto da exceção (capacity, unavailable, empty, conflict).
 */

use App\Actions\Chat\AcceptNextChatSession;
use App\Actions\Chat\CancelChatSession;
use App\Actions\Chat\ChatAgentUnavailable;
use App\Actions\Chat\ChatCapacityReached;
use App\Actions\Chat\ChatQueueEmpty;
use App\Actions\Chat\ChatSessionConflict;
use App\Actions\Chat\EndChatSession;
use App\Actions\Chat\LockAgentForAssignment;
use App\Actions\Chat\RequestChatSession;
use App\Enums\ChatSessionStatus;
use App\Models\ChatSession;
use App\Models\User;
use App\Support\ChatTransaction;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require __DIR__.'/connection.php';

[$startAt, $operation] = array_slice($argv, 1, 2);
$args = array_slice($argv, 3);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    echo match ($operation) {
        'assign' => (function () use ($args) {
            ChatTransaction::run(function () use ($args) {
                app(LockAgentForAssignment::class)(User::findOrFail($args[0]));
                ChatSession::whereKey($args[1])->update([
                    'status' => ChatSessionStatus::Active,
                    'technician_id' => $args[0],
                    'accepted_at' => now(),
                ]);
            });

            return 'ok';
        })(),
        'accept' => 'ok:'.app(AcceptNextChatSession::class)(User::findOrFail($args[0]))->id,
        'request' => 'ok:'.app(RequestChatSession::class)(User::findOrFail($args[0]), 'Concorrência')[0]->id,
        'cancel' => 'ok:'.app(CancelChatSession::class)(User::findOrFail($args[0]), ChatSession::findOrFail($args[1]))->id,
        'end' => 'ok:'.app(EndChatSession::class)(User::findOrFail($args[0]), ChatSession::findOrFail($args[1]), [
            'outcome' => 'resolved',
            'title' => 'Concorrência',
            'category_id' => (int) $args[2],
            'summary' => 'Resolvido.',
        ])[0]->ticket->ticket_number,
    };
} catch (ChatCapacityReached) {
    echo 'capacity';
} catch (ChatAgentUnavailable) {
    echo 'unavailable';
} catch (ChatQueueEmpty) {
    echo 'empty';
} catch (ChatSessionConflict) {
    echo 'conflict';
}
