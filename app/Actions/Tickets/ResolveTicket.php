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
 * Resolução (CHA-09, RF-10, RF-12). Leva o chamado de `Em atendimento` a `Resolvido`, grava o resumo
 * da solução e o instante oficial (`resolved_at`). Status, solução e evento de auditoria são
 * gravados juntos ou não são gravados; a linha fica bloqueada entre a verificação e a gravação.
 *
 * Cada resolução gera um evento próprio com o resumo, o autor e o instante, e o evento guarda a
 * resolução anterior que estiver sendo substituída: depois de uma reabertura (CHA-10), uma nova
 * resolução não apaga a rastreabilidade das anteriores (RF-12, regras 6 a 8).
 *
 * SLA: ainda não há motor de cálculo (Etapa 7). A resolução grava somente `resolved_at`; a situação
 * do SLA de resolução continua sendo a comparação de Ticket::slaState() entre esse instante e o
 * prazo gravado. Nenhum prazo, primeira resposta ou violação é alterado aqui.
 */
class ResolveTicket
{
    /**
     * @param  TicketStatus  $expected  Status que o usuário viu ao abrir a resolução.
     *
     * @throws TicketStatusConflict
     * @throws ValidationException
     */
    public function __invoke(User $editor, Ticket $ticket, TicketStatus $expected, string $summary): void
    {
        DB::transaction(function () use ($editor, $ticket, $expected, $summary) {
            $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            // Outra pessoa mudou o status depois que a resolução foi aberta.
            if ($current->status !== $expected) {
                throw new TicketStatusConflict;
            }

            if (! $current->status->resolvable()) {
                throw ValidationException::withMessages([
                    'status' => 'Este chamado não pode ser resolvido no estado atual.',
                ]);
            }

            // Permissão e acesso conferidos de novo sobre o estado bloqueado.
            Gate::forUser($editor)->authorize('resolve', $current);

            // Um chamado em atendimento não deveria ter pausa aberta; resolvê-lo deixaria a pausa
            // correndo depois do fim do atendimento (RF-15.2, regra 5).
            if ($current->slaPauses()->active()->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Este chamado possui uma pausa de SLA em andamento. Recarregue a página e confira o status.',
                ]);
            }

            $now = now();
            $previous = $current->status;

            $current->histories()->forceCreate([
                'user_id' => $editor->id,
                'event_type' => TicketEvent::Resolved,
                'old_values' => [
                    'status' => $previous->value,
                    'label' => $previous->label(),
                    // Resolução de um ciclo anterior, preservada no histórico antes de ser substituída.
                    ...($current->resolved_at ? [
                        'resolution_summary' => $current->resolution_summary,
                        'resolved_at' => $current->resolved_at->toIso8601String(),
                    ] : []),
                ],
                'new_values' => [
                    'status' => TicketStatus::Resolved->value,
                    'label' => TicketStatus::Resolved->label(),
                    'resolution_summary' => $summary,
                    'resolved_at' => $now->toIso8601String(),
                    // Prazo vigente no momento da resolução, para a apuração não depender do estado futuro.
                    'resolution_due_at' => $current->resolution_due_at?->toIso8601String(),
                ],
                'created_at' => $now,
            ]);

            $current->forceFill([
                'status' => TicketStatus::Resolved,
                'resolution_summary' => $summary,
                'resolved_at' => $now,
            ])->save();

            $ticket->setRawAttributes($current->getAttributes(), true);
        });
    }
}
