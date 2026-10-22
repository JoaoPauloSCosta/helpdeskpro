<?php

namespace App\Actions\Roles;

use App\Enums\AdminEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Criação de papel (PER-02, RF-03). O papel é personalizado (`is_system` falso) e nasce sem
 * permissões: a associação é feita na matriz (PER-04). O papel e o evento de auditoria (RF-19) são
 * gravados juntos ou não são gravados.
 */
class CreateRole
{
    /**
     * @param  array{name: string, description: ?string}  $data
     */
    public function __invoke(User $operator, array $data, string $slug): Role
    {
        $role = (new Role)->forceFill([
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        DB::transaction(function () use ($role, $operator) {
            $role->save();

            $role->auditLogs()->forceCreate([
                'user_id' => $operator->id,
                'event_type' => AdminEvent::RoleCreated,
                'old_values' => null,
                'new_values' => [
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'is_system' => $role->is_system,
                    // Registrado explicitamente: a criação não concede nenhuma permissão.
                    'permissions' => [],
                ],
                'created_at' => $role->created_at,
            ]);
        });

        return $role;
    }
}
