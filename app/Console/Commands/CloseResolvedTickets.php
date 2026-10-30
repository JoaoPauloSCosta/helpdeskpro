<?php

namespace App\Console\Commands;

use App\Actions\Tickets\AutoCloseTicket;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Console\Command;

/**
 * Encerra os chamados resolvidos que o solicitante não reabriu no prazo (RF-14, regra 9). A decisão
 * final é de AutoCloseTicket sobre a linha bloqueada; rodar de novo ou em paralelo com uma
 * reabertura ou um encerramento manual não duplica nem desfaz nada.
 */
class CloseResolvedTickets extends Command
{
    protected $signature = 'tickets:close-resolved';

    protected $description = 'Encerra os chamados resolvidos há mais de 3 dias sem reabertura';

    private const CHUNK = 200;

    public function handle(AutoCloseTicket $autoClose): int
    {
        $now = now();
        $closed = 0;

        Ticket::query()
            ->where('status', TicketStatus::Resolved)
            ->where('resolved_at', '<=', $now->copy()->subDays(AutoCloseTicket::AUTO_CLOSE_DAYS))
            ->chunkById(self::CHUNK, function ($tickets) use ($autoClose, $now, &$closed) {
                foreach ($tickets as $ticket) {
                    $closed += (int) $autoClose($ticket, $now);
                }
            });

        $this->info("Chamados encerrados: {$closed}.");

        return self::SUCCESS;
    }
}
