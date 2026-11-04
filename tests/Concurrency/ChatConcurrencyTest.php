<?php

use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatSession;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Concorrência real no MariaDB (docs/chat/02-system-design-chat.md, 4.3). Fora da suíte padrão:
 *   docker compose exec app php artisan test tests/Concurrency
 * Pula sozinho se o banco `helpdesk_testing` não estiver acessível.
 */
beforeEach(function () {
    require __DIR__.'/connection.php';

    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('MariaDB de testes indisponível: '.$e->getMessage());
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Executa os comandos em processos paralelos que começam juntos e devolve a saída de cada um, na ordem.
 *
 * @param  list<list<string|int>>  $commands  Operação e argumentos de worker.php.
 * @return list<string>
 */
function runTogether(array $commands): array
{
    $startAt = (string) (microtime(true) + 2);

    return Process::pool(function (Pool $pool) use ($commands, $startAt) {
        foreach ($commands as $command) {
            $pool->command([PHP_BINARY, __DIR__.'/worker.php', $startAt, ...array_map('strval', $command)]);
        }
    })->start()->wait()->collect()->map(fn ($result) => trim($result->output()).trim($result->errorOutput()))->values()->all();
}

function onDutyTechnician(int $max = 3): User
{
    $tech = User::factory()->withRole('technician')->create();
    ChatAgent::forceCreate(['user_id' => $tech->id, 'is_available' => true, 'max_sessions' => $max, 'last_seen_at' => now()->addMinutes(5)]);

    return $tech;
}

function succeeded(array $outputs): array
{
    return array_values(array_filter($outputs, fn ($out) => str_starts_with($out, 'ok')));
}

test('simultaneous assignments never exceed the technician capacity', function () {
    $tech = onDutyTechnician(max: 2);
    $sessions = ChatSession::factory()->count(8)->create();

    $outputs = runTogether($sessions->map(fn (ChatSession $s) => ['assign', $tech->id, $s->id])->all());

    expect(collect($outputs)->countBy()->sortKeys()->all())->toBe(['capacity' => 6, 'ok' => 2])
        ->and(ChatSession::where('technician_id', $tech->id)->where('status', ChatSessionStatus::Active)->count())->toBe(2);
});

test('technicians accepting at the same time never take the same session', function () {
    $techs = collect(range(1, 6))->map(fn () => onDutyTechnician());
    $sessions = ChatSession::factory()->count(4)->create();

    $outputs = runTogether($techs->map(fn (User $tech) => ['accept', $tech->id])->all());
    $taken = collect(succeeded($outputs))->map(fn ($out) => (int) substr($out, 3))->sort()->values()->all();

    expect($taken)->toBe($sessions->pluck('id')->all(), implode(' | ', $outputs))
        ->and(collect($outputs)->countBy()->get('empty'))->toBe(2)
        ->and(ChatSession::where('status', ChatSessionStatus::Active)->distinct()->count('technician_id'))->toBe(4);
});

test('one technician clicking several times at once never exceeds the capacity and keeps FIFO', function () {
    $tech = onDutyTechnician(max: 2);
    $sessions = ChatSession::factory()->count(5)->create();

    $outputs = runTogether(array_fill(0, 5, ['accept', $tech->id]));

    expect(count(succeeded($outputs)))->toBe(2)
        ->and(collect($outputs)->countBy()->get('capacity'))->toBe(3)
        // As duas mais antigas foram assumidas; as outras continuam aguardando.
        ->and(ChatSession::where('status', ChatSessionStatus::Active)->orderBy('id')->pluck('id')->all())->toBe($sessions->take(2)->pluck('id')->all());
});

test('simultaneous requests from the same requester create a single session', function () {
    onDutyTechnician();
    $requester = User::factory()->withRole('requester')->create(['department_id' => Department::factory()->create()->id]);

    $outputs = runTogether(array_fill(0, 6, ['request', $requester->id]));
    $session = ChatSession::sole();

    expect(array_unique($outputs))->toBe(["ok:{$session->id}"])
        ->and($session->events()->count())->toBe(1);
});

test('cancelling and accepting at the same time have exactly one winner', function () {
    foreach (range(1, 5) as $round) {
        $tech = onDutyTechnician();
        $session = ChatSession::factory()->create();

        [$cancel, $accept] = runTogether([['cancel', $session->requester_id, $session->id], ['accept', $tech->id]]);
        $status = $session->fresh()->status;

        $consistent = match ($status) {
            ChatSessionStatus::Cancelled => $cancel === "ok:{$session->id}" && $accept === 'empty',
            ChatSessionStatus::Active => $accept === "ok:{$session->id}" && $cancel === 'conflict',
            default => false,
        };

        expect($consistent)->toBeTrue("Rodada {$round}: {$status->value}, cancelar={$cancel}, atender={$accept}");
    }
});

test('closing the same session many times at once creates exactly one ticket', function () {
    foreach (range(1, 3) as $round) {
        $tech = onDutyTechnician();
        $category = TicketCategory::factory()->create(['is_active' => true]);
        $session = ChatSession::factory()->active($tech)->create(['department_id' => Department::factory()->create()->id]);

        $outputs = runTogether(array_fill(0, 6, ['end', $tech->id, $session->id, $category->id]));
        $session = $session->fresh();

        expect($session->status)->toBe(ChatSessionStatus::Ended, "Rodada {$round}: ".implode(' | ', $outputs))
            ->and(Ticket::where('requester_id', $session->requester_id)->count())->toBe(1)
            ->and(array_unique($outputs))->toBe(["ok:{$session->ticket->ticket_number}"])
            ->and($session->events()->where('event_type', 'ended')->count())->toBe(1)
            ->and($session->ticket->histories()->count())->toBe(3);
    }
});
