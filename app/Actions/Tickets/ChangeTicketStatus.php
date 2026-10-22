<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Alteração de status (CHA-07, RF-10). Executa somente as transições que o RF-10 atribui a este
 * fluxo e altera apenas `status`. O chamado e o evento de auditoria são gravados juntos ou não
 * são gravados; a linha fica bloqueada entre a verificação e a gravação.
 *
 * SLA (RF-15.2, RF-17): na retomada, a pausa aberta pelo registro de pendência (CHA-08) é
 * encerrada na mesma transação, com fim e duração calculados aqui. Ainda não há motor de cálculo
 * (Etapa 7): aplicar o tempo restante ao prazo é o próximo passo neste mesmo ponto. Até lá, nenhum
 * prazo, primeira resposta ou violação gravada é alterado.
 */
class ChangeTicketStatus
{
    /**
     * @param  TicketStatus  $expected  Status que o usuário viu ao escolher a transição.
     *
     * @throws TicketStatusConflict
     * @throws ValidationException
     */
    public function __invoke(User $editor, Ticket $ticket, TicketStatus $expected, TicketStatus $target): void
    {
        DB::transaction(function () use ($editor, $ticket, $expected, $target) {
            $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            // Outra pessoa mudou o status depois que a alteração foi aberta.
            if ($current->status !== $expected) {
                throw new TicketStatusConflict;
            }

            if (! in_array($target, TicketPolicy::statusChangeTargets($current), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Esta alteração de status não está disponível para o chamado no estado atual.',
                ]);
            }

            // Permissão e acesso conferidos de novo sobre o estado bloqueado.
            Gate::forUser($editor)->authorize('changeStatus', [$current, $target]);

            $now = now();
            $previous = $current->status;

            // Retomada (RF-17, regra 2). Chamados pausados antes do CHA-08 não têm pausa registrada.
            $pause = in_array($previous, TicketStatus::paused(), true)
                ? $current->slaPauses()->active()->lockForUpdate()->first()
                : null;
            $pause?->close($now);

            $current->forceFill(['status' => $target])->save();

            $current->histories()->forceCreate([
                'user_id' => $editor->id,
                'event_type' => TicketEvent::StatusChanged,
                'old_values' => ['status' => $previous->value, 'label' => $previous->label()],
                'new_values' => [
                    'status' => $target->value,
                    'label' => $target->label(),
                    ...($pause ? ['sla_pause' => [
                        'indicator' => 'resolution',
                        'started_at' => $pause->started_at->toIso8601String(),
                        'ended_at' => $pause->ended_at->toIso8601String(),
                        'duration_seconds' => $pause->duration_seconds,
                    ]] : []),
                ],
                'created_at' => $now,
            ]);

            $ticket->setRawAttributes($current->getAttributes(), true);
        });
    }
}
