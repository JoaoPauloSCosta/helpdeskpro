<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Support\SlaClock;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Registra as violações de SLA dos chamados parados (D4, RN-15). As ações sobre o chamado já
 * registram o que venceu até ali; este comando cobre os chamados em que ninguém agiu depois do prazo.
 *
 * Só olha o que ainda não tem violação registrada e já passou do prazo: primeira resposta sem
 * resposta (ou respondida fora do prazo) e resolução de chamados em andamento. Chamados pausados
 * ficam de fora, porque o relógio de resolução está parado (a violação já vencida antes da pausa
 * foi registrada ao pausar), e os resolvidos ou encerrados, apurados na própria resolução. Quem
 * grava é SlaClock::recordBreaches(), com a linha do chamado bloqueada, então rodar de novo ou em
 * paralelo com uma ação do chamado não duplica nada.
 */
class RecordSlaBreaches extends Command
{
    protected $signature = 'sla:record-breaches';

    protected $description = 'Registra as violações de SLA de chamados com prazo vencido e sem violação registrada';

    private const CHUNK = 200;

    public function handle(): int
    {
        $now = now();
        $recorded = 0;

        $this->candidates($now)->chunkById(self::CHUNK, function ($tickets) use ($now, &$recorded) {
            foreach ($tickets as $candidate) {
                $recorded += DB::transaction(function () use ($candidate, $now) {
                    $ticket = Ticket::whereKey($candidate->getKey())->lockForUpdate()->first();

                    return $ticket ? count(SlaClock::recordBreaches($ticket, $now)) : 0;
                });
            }
        });

        $this->info("Violações registradas: {$recorded}.");

        return self::SUCCESS;
    }

    /** Pré-filtro barato; a decisão final é de SlaClock::recordBreaches() sobre a linha bloqueada. */
    private function candidates(CarbonInterface $now): Builder
    {
        $running = array_map(fn (TicketStatus $status) => $status->value, TicketStatus::running());

        return Ticket::query()->where(function (Builder $query) use ($now, $running) {
            $query->where(fn (Builder $query) => $query
                ->whereNull('first_response_breached_at')
                ->where('first_response_due_at', '<', $now)
                ->where(fn (Builder $query) => $query
                    ->whereNull('first_response_at')
                    ->orWhereColumn('first_response_at', '>', 'first_response_due_at')))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('resolution_breached_at')
                    ->where('resolution_due_at', '<', $now)
                    ->whereIn('status', $running));
        });
    }
}
