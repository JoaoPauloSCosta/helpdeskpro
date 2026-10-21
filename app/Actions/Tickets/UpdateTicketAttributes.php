<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edição operacional (CHA-04, RF-18). Aplica apenas os atributos que mudaram e grava um evento
 * de auditoria por atributo, com valores anteriores e novos, na mesma transação.
 *
 * SLA (RF-16): ainda não há políticas de SLA nem motor de cálculo (Etapa 7), então nenhum
 * prazo é recalculado aqui. Os prazos e violações gravados no chamado não são tocados, e o
 * evento de prioridade preserva o histórico das prioridades aplicadas para o recálculo futuro.
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

            if (isset($changes['priority']) && $changes['priority'] !== $current->priority->value) {
                $events[] = [TicketEvent::PriorityChanged, $this->priority($current->priority), $this->priority(TicketPriority::from($changes['priority']))];
                $current->priority = TicketPriority::from($changes['priority']);
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

            $ticket->setRawAttributes($current->getAttributes(), true);

            return array_column($events, 0);
        });
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
