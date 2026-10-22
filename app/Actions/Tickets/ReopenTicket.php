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
 * Reabertura (CHA-10, RF-10, RF-13). Leva o chamado de `Resolvido` de volta a `Aberto` pelo fluxo
 * normal do solicitante original, com justificativa obrigatória. Status e evento de auditoria são
 * gravados juntos ou não são gravados; a linha fica bloqueada entre a verificação e a gravação.
 *
 * A justificativa fica no próprio evento `Reopened`, um por reabertura: ciclos anteriores nunca são
 * sobrescritos. A solução registrada (`resolution_summary`, `resolved_at`) permanece no chamado e é
 * copiada para o evento; uma nova resolução (CHA-09) a preserva no histórico antes de substituí-la.
 * Identificador, solicitante, responsável, prioridade, categoria e departamento não mudam (RF-13, regra 7).
 *
 * SLA (RF-13, regra 8): ainda não há motor de cálculo (Etapa 7). Nada é reiniciado nem recalculado:
 * prazos, primeira resposta, pausas e violações gravados ficam como estão; o evento guarda os prazos
 * vigentes no momento da reabertura. Como o tempo entre a resolução e a reabertura entra na apuração
 * é uma regra a definir junto do motor de SLA.
 */
class ReopenTicket
{
    /**
     * @param  TicketStatus  $expected  Status que o usuário viu ao abrir a reabertura.
     *
     * @throws TicketStatusConflict
     * @throws ValidationException
     */
    public function __invoke(User $requester, Ticket $ticket, TicketStatus $expected, string $justification): void
    {
        DB::transaction(function () use ($requester, $ticket, $expected, $justification) {
            $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            // Outra pessoa mudou o status (por exemplo, encerrou o chamado) depois que a reabertura foi aberta.
            if ($current->status !== $expected) {
                throw new TicketStatusConflict;
            }

            if (! $current->status->reopenable()) {
                throw ValidationException::withMessages([
                    'status' => 'Este chamado não pode ser reaberto no estado atual.',
                ]);
            }

            // Propriedade e acesso conferidos de novo sobre o estado bloqueado.
            Gate::forUser($requester)->authorize('reopen', $current);

            $now = now();
            $previous = $current->status;

            $current->histories()->forceCreate([
                'user_id' => $requester->id,
                'event_type' => TicketEvent::Reopened,
                'old_values' => [
                    'status' => $previous->value,
                    'label' => $previous->label(),
                    // Solução do ciclo que está sendo reaberto.
                    'resolution_summary' => $current->resolution_summary,
                    'resolved_at' => $current->resolved_at?->toIso8601String(),
                ],
                'new_values' => [
                    'status' => TicketStatus::Open->value,
                    'label' => TicketStatus::Open->label(),
                    'justification' => $justification,
                    'reopened_at' => $now->toIso8601String(),
                    // Prazos vigentes na reabertura: o SLA original não é reiniciado (RF-13, regra 8).
                    'first_response_due_at' => $current->first_response_due_at?->toIso8601String(),
                    'resolution_due_at' => $current->resolution_due_at?->toIso8601String(),
                ],
                'created_at' => $now,
            ]);

            $current->forceFill(['status' => TicketStatus::Open])->save();

            $ticket->setRawAttributes($current->getAttributes(), true);
        });
    }
}
