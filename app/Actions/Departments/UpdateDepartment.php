<?php

namespace App\Actions\Departments;

use App\Enums\AdminEvent;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edição de departamento (ADM-06, RF-02). Aplica apenas o que mudou e grava a auditoria (RF-19) na
 * mesma transação: um `DepartmentUpdated` com os dados alterados e, se a situação mudar, um
 * `DepartmentDeactivated` ou `DepartmentActivated`.
 *
 * O registro é atualizado no lugar: chamados, usuários e históricos continuam apontando para o mesmo
 * departamento, e os eventos de chamados guardam o nome da época em que foram gravados.
 */
class UpdateDepartment
{
    /**
     * @param  array{name: string, description: ?string, status: string}  $changes
     * @return list<AdminEvent> Eventos gravados; vazio quando nada mudou.
     *
     * @throws DepartmentEditConflict
     */
    public function __invoke(User $operator, Department $department, array $changes, string $version): array
    {
        return DB::transaction(function () use ($operator, $department, $changes, $version) {
            // Bloqueia a linha para que duas edições simultâneas não se sobreponham.
            $current = Department::whereKey($department->getKey())->lockForUpdate()->firstOrFail();

            if (! hash_equals($current->editVersion(), $version)) {
                throw new DepartmentEditConflict;
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
                $events[] = $this->record($current, $operator, AdminEvent::DepartmentUpdated, $old, $new);
            }

            if ($statusChanged) {
                $events[] = $this->record(
                    $current,
                    $operator,
                    $active ? AdminEvent::DepartmentActivated : AdminEvent::DepartmentDeactivated,
                    ['is_active' => ! $active],
                    ['is_active' => $active],
                );
            }

            $department->setRawAttributes($current->getAttributes(), true);

            return $events;
        });
    }

    private function record(Department $department, User $operator, AdminEvent $event, array $old, array $new): AdminEvent
    {
        $department->auditLogs()->forceCreate([
            'user_id' => $operator->id,
            'event_type' => $event,
            'old_values' => $old,
            'new_values' => $new,
            'created_at' => now(),
        ]);

        return $event;
    }
}
