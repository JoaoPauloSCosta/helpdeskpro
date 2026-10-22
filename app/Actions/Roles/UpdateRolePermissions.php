<?php

namespace App\Actions\Roles;

use App\Enums\AdminEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Matriz de permissões (PER-04, RF-03). Recebe o conjunto completo desejado para o papel e aplica só
 * a diferença: associações mantidas não são regravadas, e o catálogo e os usuários do papel não são
 * tocados. Inclusões, remoções e o evento de auditoria (RF-19) são gravados juntos ou não são gravados.
 */
class UpdateRolePermissions
{
    /**
     * @param  list<string>  $slugs  Permissões que o papel deve ter ao final.
     * @return array{added: list<string>, removed: list<string>} Alterações efetivamente gravadas.
     *
     * @throws RolePermissionsConflict
     * @throws PermissionsNotGrantable
     */
    public function __invoke(User $operator, Role $role, array $slugs, string $version): array
    {
        return DB::transaction(function () use ($operator, $role, $slugs, $version) {
            // Bloqueia o papel para que duas gravações simultâneas da matriz não se sobreponham.
            Role::whereKey($role->getKey())->lockForUpdate()->firstOrFail();

            $current = $role->permissions()->pluck('slug')->all();

            if (! hash_equals($role->permissionsVersion($current), $version)) {
                throw new RolePermissionsConflict;
            }

            $added = array_values(array_diff($slugs, $current));
            $removed = array_values(array_diff($current, $slugs));

            if ($added === [] && $removed === []) {
                return ['added' => [], 'removed' => []];
            }

            // Permissões administrativas só entram ou saem por quem também as possui.
            $notGrantable = array_diff(
                array_intersect([...$added, ...$removed], Permission::ADMINISTRATIVE),
                $operator->effectivePermissions(),
            );

            if ($notGrantable !== []) {
                throw new PermissionsNotGrantable(
                    Permission::whereIn('slug', $notGrantable)->orderBy('name')->pluck('name')->all(),
                );
            }

            $ids = Permission::whereIn('slug', [...$added, ...$removed])->pluck('id', 'slug');

            if ($removed !== []) {
                $role->permissions()->detach($ids->only($removed)->values());
            }

            if ($added !== []) {
                $role->permissions()->attach($ids->only($added)->values(), ['created_at' => now()]);
            }

            sort($current);
            sort($added);
            sort($removed);
            $after = array_values(array_unique([...array_diff($current, $removed), ...$added]));
            sort($after);

            $role->auditLogs()->forceCreate([
                'user_id' => $operator->id,
                'event_type' => AdminEvent::RolePermissionsUpdated,
                'old_values' => ['permissions' => $current],
                'new_values' => ['permissions' => $after, 'added' => $added, 'removed' => $removed],
                'created_at' => now(),
            ]);

            return ['added' => $added, 'removed' => $removed];
        });
    }
}
