<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\SlaClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Edição operacional (CHA-04, RF-18). Aplica apenas os atributos que mudaram e grava um evento
 * de auditoria por atributo, com valores anteriores e novos, na mesma transação.
 *
 * SLA (RF-16, RN-14): a troca de prioridade aplica a política ativa da nova prioridade a partir
 * deste instante, preservando o tempo já consumido, as pausas e as violações.
 *  - Antes de recalcular, violações já vencidas são registradas, para que uma meta mais longa não
 *    as apague. As violações gravadas nunca são alteradas.
 *  - Primeira resposta: sem resposta ainda, o prazo passa a ser `created_at` + a nova meta. Com
 *    resposta, nada muda (RF-16.1, regra 5).
 *  - Resolução: `prazo = relógio + (nova meta − tempo consumido)`. O relógio é o início da pausa,
 *    se o chamado está pausado (a retomada soma a duração). Chamados resolvidos ou encerrados não
 *    são recalculados.
 *  - Sem política ativa na nova prioridade (D2), o chamado fica sem prazo, e o evento registra isso.
 *    Se a prioridade antiga também não tinha política, nada de SLA muda.
 * Cada recálculo gera o evento `SlaTargetsChanged`, com metas, prazos e tempo consumido.
 */
class UpdateTicketAttributes
{
    /**
     * @param  array{priority?: string, category_id?: int, department_id?: int}  $changes
     * @return list<TicketEvent> Eventos gravados; vazio quando nada mudou.
     *
     * @throws TicketEditConflict
     */
    public function __invoke(User $editor, Ticket $ticket, array $changes, string $version): array
    {
        return DB::transaction(function () use ($editor, $ticket, $changes, $version) {
            // Bloqueia a linha para que duas edições simultâneas não se sobreponham.
            $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if (! hash_equals($current->editVersion(), $version)) {
                throw new TicketEditConflict;
            }

            $events = [];
            $now = now();
            $priorityChanged = isset($changes['priority']) && $changes['priority'] !== $current->priority->value;

            if ($priorityChanged) {
                // Antes de mexer nos prazos: uma violação já vencida não pode se perder (RF-16.3, RN-15).
                SlaClock::recordBreaches($current, $now);

                $events[] = [TicketEvent::PriorityChanged, $this->priority($current->priority), $this->priority(TicketPriority::from($changes['priority']))];
                $oldPriority = $current->priority;
                $current->priority = TicketPriority::from($changes['priority']);
                $slaEvent = $this->recalculateSla($current, $oldPriority, $now);

                if ($slaEvent) {
                    $events[] = $slaEvent;
                }
            }

            if (isset($changes['category_id']) && $changes['category_id'] !== $current->category_id) {
                $events[] = [TicketEvent::CategoryChanged, $this->named(TicketCategory::find($current->category_id)), $this->named(TicketCategory::find($changes['category_id']))];
                $current->category_id = $changes['category_id'];
            }

            if (isset($changes['department_id']) && $changes['department_id'] !== $current->department_id) {
                $events[] = [TicketEvent::DepartmentTransferred, $this->named(Department::find($current->department_id)), $this->named(Department::find($changes['department_id']))];
                $current->department_id = $changes['department_id'];
            }

            if ($events === []) {
                return [];
            }

            $current->save();

            foreach ($events as [$event, $old, $new]) {
                $current->histories()->forceCreate([
                    'user_id' => $editor->id,
                    'event_type' => $event,
                    'old_values' => $old,
                    'new_values' => $new,
                ]);
            }

            // Com a meta nova já gravada: se o tempo consumido supera a nova meta, o chamado nasce vencido.
            if ($priorityChanged) {
                SlaClock::recordBreaches($current, $now);
            }

            $ticket->setRawAttributes($current->getAttributes(), true);

            return array_column($events, 0);
        });
    }

    /**
     * Aplica a política da nova prioridade ao chamado (ainda não salvo) e devolve o evento
     * `SlaTargetsChanged`, ou `null` quando não há o que recalcular.
     *
     * @return array{0: TicketEvent, 1: array, 2: array}|null
     */
    private function recalculateSla(Ticket $ticket, TicketPriority $oldPriority, CarbonInterface $now): ?array
    {
        if (in_array($ticket->status, TicketStatus::finished(), true)) {
            return null;
        }

        $policy = SlaClock::policyFor($ticket->priority);

        if ($policy === null && $ticket->sla_policy_id === null) {
            return null;
        }

        $old = $this->slaSnapshot($ticket, $oldPriority);

        // Chamado que tinha meta: o consumo sai do prazo vigente. Sem meta antes, sai dos fatos registrados.
        $consumed = $ticket->sla_resolution_minutes !== null && $ticket->resolution_due_at !== null
            ? SlaClock::consumedResolutionSeconds($ticket, $now)
            : SlaClock::effectiveElapsedSeconds($ticket, $now);

        $clock = SlaClock::clockInstant($ticket, $now);

        $ticket->forceFill([
            'sla_policy_id' => $policy?->id,
            'sla_resolution_minutes' => $policy?->resolution_minutes,
            'resolution_due_at' => $policy ? $clock->copy()->addSeconds($policy->resolution_minutes * 60 - $consumed) : null,
        ]);

        if ($ticket->first_response_at === null) {
            $ticket->forceFill([
                'sla_first_response_minutes' => $policy?->first_response_minutes,
                'first_response_due_at' => $policy ? $ticket->created_at->copy()->addMinutes($policy->first_response_minutes) : null,
            ]);
        }

        return [
            TicketEvent::SlaTargetsChanged,
            $old,
            [...$this->slaSnapshot($ticket, $ticket->priority), 'consumed_seconds' => $consumed],
        ];
    }

    /** Prioridade, política, metas e prazos de SLA, como o evento os apresenta. */
    private function slaSnapshot(Ticket $ticket, TicketPriority $priority): array
    {
        return [
            'priority' => $this->priority($priority),
            'policy_id' => $ticket->sla_policy_id,
            'first_response_minutes' => $ticket->sla_first_response_minutes,
            'resolution_minutes' => $ticket->sla_resolution_minutes,
            'first_response_due_at' => $ticket->first_response_due_at?->toIso8601String(),
            'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
        ];
    }

    private function priority(TicketPriority $priority): array
    {
        return ['priority' => $priority->value, 'label' => $priority->label()];
    }

    /** Guarda também o nome, para o histórico continuar legível se o cadastro for renomeado. */
    private function named(TicketCategory|Department|null $model): array
    {
        return ['id' => $model?->id, 'label' => $model?->name];
    }
}
