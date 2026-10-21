<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Atribuição e reatribuição manual (CHA-05, RF-08). Altera somente `assigned_to`: o status
 * permanece o mesmo (a mudança para "Em atendimento" é exclusiva da autoatribuição, RF-09).
 * O chamado e o evento de auditoria são gravados juntos ou não são gravados.
 */
class AssignTicket
{
    /**
     * @return TicketEvent Atribuição ou reatribuição, decidido pelo estado no momento da gravação.
     *
     * @throws TicketAssignmentConflict
     * @throws ValidationException
     */
    public function __invoke(User $editor, Ticket $ticket, int $technicianId, ?int $expectedAssigneeId): TicketEvent
    {
        return DB::transaction(function () use ($editor, $ticket, $technicianId, $expectedAssigneeId) {
            $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            // Outra pessoa mudou o responsável depois que o modal foi aberto.
            if ($current->assigned_to !== $expectedAssigneeId) {
                throw new TicketAssignmentConflict;
            }

            // Permissão conferida de novo sobre o estado bloqueado: atribuir e reatribuir são distintos.
            $event = $current->assigned_to === null ? TicketEvent::Assigned : TicketEvent::Reassigned;
            Gate::forUser($editor)->authorize($event === TicketEvent::Assigned ? 'assign' : 'reassign', $current);

            $technician = User::eligibleAssignees()->find($technicianId);

            if ($technician === null) {
                throw ValidationException::withMessages(['technician_id' => 'Selecione um técnico ativo e elegível.']);
            }

            if ($technician->id === $current->assigned_to) {
                throw ValidationException::withMessages(['technician_id' => 'Este técnico já é o responsável pelo chamado.']);
            }

            $previous = $current->assignee()->first(['id', 'name']);

            $current->forceFill(['assigned_to' => $technician->id])->save();

            $current->histories()->forceCreate([
                'user_id' => $editor->id,
                'event_type' => $event,
                'old_values' => $previous ? ['id' => $previous->id, 'label' => $previous->name] : null,
                'new_values' => ['id' => $technician->id, 'label' => $technician->name],
            ]);

            $ticket->setRawAttributes($current->getAttributes(), true);

            return $event;
        });
    }
}
