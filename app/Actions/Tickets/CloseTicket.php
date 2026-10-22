<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Encerramento (CHA-11, RF-10, RF-14). Leva o chamado de `Resolvido` a `Encerrado` e grava o instante
 * oficial (`closed_at`). Status e evento de auditoria são gravados juntos ou não são gravados; a linha
 * fica bloqueada entre a verificação e a gravação.
 *
 * Só status e `closed_at` mudam: descrição, solução registrada (`resolution_summary`, `resolved_at`),
 * responsável, comentários, pendências e histórico ficam como estão. O PRD não prevê justificativa
 * para o encerramento.
 *
 * SLA: ainda não há motor de cálculo (Etapa 7). Nenhum prazo, primeira resposta, pausa ou violação é
 * alterado; a situação do SLA de resolução continua sendo apurada por Ticket::slaState() com o
 * instante da resolução, e não com o do encerramento.
 */
class CloseTicket
{
    /**
     * @param  TicketStatus  $expected  Status que o usuário viu ao abrir o encerramento.
     *
     * @throws TicketStatusConflict
     * @throws ValidationException
     */
    public function __invoke(User $editor, Ticket $ticket, TicketStatus $expected): void
    {
        DB::transaction(function () use ($editor, $ticket, $expected) {
            $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            // Outra pessoa mudou o status (por exemplo, o solicitante reabriu) depois que o encerramento foi aberto.
            if ($current->status !== $expected) {
                throw new TicketStatusConflict;
            }

            if (! $current->status->closable()) {
                throw ValidationException::withMessages([
                    'status' => 'Este chamado não pode ser encerrado no estado atual.',
                ]);
            }

            // Permissão e acesso conferidos de novo sobre o estado bloqueado.
            Gate::forUser($editor)->authorize('close', $current);

            $now = now();
            $previous = $current->status;

            $current->histories()->forceCreate([
                'user_id' => $editor->id,
                'event_type' => TicketEvent::Closed,
                'old_values' => [
                    'status' => $previous->value,
                    'label' => $previous->label(),
                ],
                'new_values' => [
                    'status' => TicketStatus::Closed->value,
                    'label' => TicketStatus::Closed->label(),
                    'closed_at' => $now->toIso8601String(),
                ],
                'created_at' => $now,
            ]);

            $current->forceFill([
                'status' => TicketStatus::Closed,
                'closed_at' => $now,
            ])->save();

            $ticket->setRawAttributes($current->getAttributes(), true);
        });
    }
}
