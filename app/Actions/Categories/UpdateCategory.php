<?php

namespace App\Actions\Categories;

use App\Enums\AdminEvent;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edição de categoria (ADM-09, RF-04). Aplica apenas o que mudou e grava a auditoria (RF-19) na
 * mesma transação: um `CategoryUpdated` com os dados alterados e, se a situação mudar, um
 * `CategoryDeactivated` ou `CategoryActivated`.
 *
 * O registro é atualizado no lugar: chamados continuam apontando para a mesma categoria, e os eventos
 * de chamados guardam o nome da época em que foram gravados.
 */
class UpdateCategory
{
    /**
     * @param  array{name: string, description: ?string, status: string}  $changes
     * @return list<AdminEvent> Eventos gravados; vazio quando nada mudou.
     *
     * @throws CategoryEditConflict
     */
    public function __invoke(User $operator, TicketCategory $category, array $changes, string $version): array
    {
        return DB::transaction(function () use ($operator, $category, $changes, $version) {
            // Bloqueia a linha para que duas edições simultâneas não se sobreponham.
            $current = TicketCategory::whereKey($category->getKey())->lockForUpdate()->firstOrFail();

            if (! hash_equals($current->editVersion(), $version)) {
                throw new CategoryEditConflict;
            }

            $old = [];
            $new = [];

            foreach (['name', 'description'] as $field) {
                $value = $changes[$field] ?? null;

                if ($value !== $current->{$field}) {
                    $old[$field] = $current->{$field};
                    $new[$field] = $value;
                }
            }

            $active = $changes['status'] === 'active';
            $statusChanged = $active !== $current->is_active;

            if ($new === [] && ! $statusChanged) {
                return [];
            }

            // Atribuição explícita dos campos editáveis; identificador e datas ficam com o banco.
            $current->forceFill([
                'name' => $changes['name'],
                'description' => $changes['description'] ?? null,
                'is_active' => $active,
            ])->save();

            $events = [];

            if ($new !== []) {
                $events[] = $this->record($current, $operator, AdminEvent::CategoryUpdated, $old, $new);
            }

            if ($statusChanged) {
                $events[] = $this->record(
                    $current,
                    $operator,
                    $active ? AdminEvent::CategoryActivated : AdminEvent::CategoryDeactivated,
                    ['is_active' => ! $active],
                    ['is_active' => $active],
                );
            }

            $category->setRawAttributes($current->getAttributes(), true);

            return $events;
        });
    }

    private function record(TicketCategory $category, User $operator, AdminEvent $event, array $old, array $new): AdminEvent
    {
        $category->auditLogs()->forceCreate([
            'user_id' => $operator->id,
            'event_type' => $event,
            'old_values' => $old,
            'new_values' => $new,
            'created_at' => now(),
        ]);

        return $event;
    }
}
