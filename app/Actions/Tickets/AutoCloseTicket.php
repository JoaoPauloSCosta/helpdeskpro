<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Encerramento automático (RF-14, regra 9). Leva a `Encerrado` o chamado que continua `Resolvido`
 * depois de `AUTO_CLOSE_DAYS` dias corridos da resolução sem que o solicitante o reabrisse.
 *
 * Faz o mesmo que CloseTicket (status, `closed_at` e evento `Closed`, gravados juntos com a linha
 * bloqueada), mas sem usuário: o evento fica sem autor e marcado com `automatic`. A condição é
 * conferida de novo sobre a linha bloqueada, então uma reabertura ou um encerramento manual que
 * chegue antes faz esta chamada não fazer nada.
 */
class AutoCloseTicket
{
    public const AUTO_CLOSE_DAYS = 3;

    /** @return bool Se o chamado foi encerrado. */
    public function __invoke(Ticket $ticket, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($ticket, $now) {
            $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->first();

            if (! $current
                || $current->status !== TicketStatus::Resolved
                || ! $current->resolved_at
                || $current->resolved_at->copy()->addDays(self::AUTO_CLOSE_DAYS)->gt($now)) {
                return false;
            }

            $current->histories()->forceCreate([
                'user_id' => null,
                'event_type' => TicketEvent::Closed,
                'old_values' => [
                    'status' => TicketStatus::Resolved->value,
                    'label' => TicketStatus::Resolved->label(),
                ],
                'new_values' => [
                    'status' => TicketStatus::Closed->value,
                    'label' => TicketStatus::Closed->label(),
                    'closed_at' => $now->toIso8601String(),
                    'automatic' => true,
                    'auto_close_days' => self::AUTO_CLOSE_DAYS,
                ],
                'created_at' => $now,
            ]);

            $current->forceFill([
                'status' => TicketStatus::Closed,
                'closed_at' => $now,
            ])->save();

            return true;
        });
    }
}
