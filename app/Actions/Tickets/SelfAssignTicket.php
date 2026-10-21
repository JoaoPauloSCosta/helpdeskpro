<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Autoatribuição (CHA-06, RF-09). Responsável e status mudam juntos (`Aberto` → `Em atendimento`),
 * com o evento de auditoria na mesma transação. A linha fica bloqueada entre a verificação e a
 * gravação: de duas tentativas simultâneas, a segunda já encontra o chamado atribuído.
 */
class SelfAssignTicket
{
    /**
     * @throws TicketNotSelfAssignable
     */
    public function __invoke(User $technician, Ticket $ticket): void
    {
        DB::transaction(function () use ($technician, $ticket) {
            $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            // Estado verificado antes da permissão: perder a disputa é indisponibilidade, não acesso negado.
            if (! TicketPolicy::isSelfAssignable($current)) {
                throw new TicketNotSelfAssignable;
            }

            Gate::forUser($technician)->authorize('selfAssign', $current);

            $current->forceFill([
                'assigned_to' => $technician->id,
                'status' => TicketStatus::InProgress,
            ])->save();

            $current->histories()->forceCreate([
                'user_id' => $technician->id,
                'event_type' => TicketEvent::SelfAssigned,
                'old_values' => [
                    'assignee' => null,
                    'status' => TicketStatus::Open->value,
                    'label' => TicketStatus::Open->label(),
                ],
                'new_values' => [
                    'assignee' => ['id' => $technician->id, 'name' => $technician->name],
                    'status' => TicketStatus::InProgress->value,
                    'label' => TicketStatus::InProgress->label(),
                ],
            ]);

            $ticket->setRawAttributes($current->getAttributes(), true);
        });
    }
}
