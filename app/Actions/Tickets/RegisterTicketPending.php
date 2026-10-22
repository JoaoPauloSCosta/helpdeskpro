<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketSlaPause;
use App\Models\User;
use App\Policies\TicketPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Registro de pendência (CHA-08, RF-10, RF-17). Leva o chamado de `Em atendimento` ao status de
 * pausa escolhido e abre o período de pausa do SLA de resolução (RF-15.2). Status, pendência e
 * evento de auditoria são gravados juntos ou não são gravados; a linha do chamado fica bloqueada
 * entre a verificação e a gravação.
 *
 * SLA: ainda não há políticas nem cálculo de prazos (Etapa 7). A pausa registra o instante oficial
 * de início, e a retomada (ChangeTicketStatus) registra o fim e a duração; o motor de SLA usará
 * esses intervalos. Nenhum prazo, primeira resposta ou violação gravada é alterado aqui.
 */
class RegisterTicketPending
{
    /**
     * @param  TicketStatus  $expected  Status que o usuário viu ao abrir o registro.
     * @param  array<string, string|int>  $details  Campos específicos já validados para o tipo.
     *
     * @throws TicketStatusConflict
     * @throws ValidationException
     */
    public function __invoke(User $editor, Ticket $ticket, TicketStatus $expected, TicketStatus $type, string $reason, array $details = []): TicketSlaPause
    {
        return DB::transaction(function () use ($editor, $ticket, $expected, $type, $reason, $details) {
            $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            // Outra pessoa mudou o status depois que o registro foi aberto.
            if ($current->status !== $expected) {
                throw new TicketStatusConflict;
            }

            if (! in_array($type, TicketPolicy::pendingTargets($current), true)) {
                throw ValidationException::withMessages([
                    'pause_status' => 'Este tipo de pendência não está disponível para o chamado no estado atual.',
                ]);
            }

            // Permissão e acesso conferidos de novo sobre o estado bloqueado.
            Gate::forUser($editor)->authorize('registerPending', [$current, $type]);

            // Um chamado em atendimento não deveria ter pausa aberta; se tiver, não se abre outra
            // (RF-15.2, regra 5). O índice único do banco é a última barreira.
            if ($current->slaPauses()->active()->exists()) {
                throw ValidationException::withMessages([
                    'pause_status' => 'Este chamado já possui uma pausa de SLA em andamento. Recarregue a página e confira o status.',
                ]);
            }

            $now = now();
            $previous = $current->status;
            $details = array_intersect_key($details, array_flip($type->pendingFields()));

            $current->forceFill(['status' => $type])->save();

            $event = $current->histories()->forceCreate([
                'user_id' => $editor->id,
                'event_type' => TicketEvent::PendingRegistered,
                'old_values' => ['status' => $previous->value, 'label' => $previous->label()],
                'new_values' => [
                    'status' => $type->value,
                    'label' => $type->label(),
                    'reason' => $reason,
                    ...$details,
                    // Nome no momento do registro, para o histórico não depender do cadastro atual.
                    ...(isset($details['related_department_id'])
                        ? ['related_department' => Department::whereKey($details['related_department_id'])->value('name')]
                        : []),
                    'sla_pause' => ['indicator' => 'resolution', 'started_at' => $now->toIso8601String()],
                ],
                'created_at' => $now,
            ]);

            $pause = $current->slaPauses()->forceCreate([
                'ticket_history_id' => $event->id,
                'pause_status' => $type,
                'reason' => $reason,
                ...$details,
                'started_at' => $now,
                'created_by' => $editor->id,
            ]);

            $ticket->setRawAttributes($current->getAttributes(), true);

            return $pause;
        });
    }
}
