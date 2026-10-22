<?php

namespace App\Actions\Roles;

use App\Enums\AdminEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edição cadastral de papel (PER-03, RF-03). Altera só nome e descrição e grava a auditoria (RF-19)
 * na mesma transação. O slug é o identificador interno e não muda; permissões (PER-04) e usuários
 * vinculados não são tocados, então a edição não concede nem retira acesso.
 */
class UpdateRole
{
    /**
     * @param  array{name: string, description: ?string}  $changes
     * @return bool Se algo mudou.
     *
     * @throws RoleEditConflict
     */
    public function __invoke(User $operator, Role $role, array $changes, string $version): bool
    {
        return DB::transaction(function () use ($operator, $role, $changes, $version) {
            // Bloqueia a linha para que duas edições simultâneas não se sobreponham.
            $current = Role::whereKey($role->getKey())->lockForUpdate()->firstOrFail();

            if (! hash_equals($current->editVersion(), $version)) {
                throw new RoleEditConflict;
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

            if ($new === []) {
                return false;
            }

            // Atribuição explícita dos campos editáveis; os demais ficam como estão.
            $current->forceFill($new)->save();

            $current->auditLogs()->forceCreate([
                'user_id' => $operator->id,
                'event_type' => AdminEvent::RoleUpdated,
                'old_values' => $old,
                'new_values' => $new,
                'created_at' => now(),
            ]);

            $role->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }
}
