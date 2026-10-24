<?php

namespace App\Http\Controllers;

use App\Actions\Roles\PermissionsNotGrantable;
use App\Actions\Roles\RolePermissionsConflict;
use App\Actions\Roles\UpdateRolePermissions;
use App\Http\Requests\Roles\UpdateRolePermissionsRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RolePermissionController extends Controller
{
    /**
     * PER-04 — Matriz de permissões (RF-03). Consultar exige `roles.manage`; alterar um papel exige
     * também RolePolicy::managePermissions(). Nenhum papel é selecionado automaticamente: o operador
     * escolhe qual consultar (`?role=`).
     */
    public function edit(Request $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        $validated = $request->validate(['role' => ['nullable', 'integer', 'min:1']]);
        $operator = $request->user();

        $roles = Role::query()
            ->with('permissions:id,slug')
            ->withCount('permissions')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'is_system']);

        $selected = isset($validated['role'])
            ? $roles->firstWhere('id', (int) $validated['role']) ?? abort(404)
            : null;

        return Inertia::render('Admin/Roles/Permissions', [
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => $role->is_system,
                'permissions_count' => $role->permissions_count,
            ])->values(),
            'selected' => $selected ? $this->selectedRole($selected, $operator) : null,
            'catalog' => $this->catalog($operator),
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, UpdateRolePermissions $updatePermissions): RedirectResponse
    {
        $target = $request->target();

        try {
            ['added' => $added, 'removed' => $removed] = $updatePermissions(
                $request->user(),
                $target,
                $request->slugs(),
                $request->validated('version'),
            );
        } catch (RolePermissionsConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'As permissões deste papel foram alteradas por outra pessoa enquanto você editava. '
                    .'Recarregue os dados atuais antes de tentar novamente.',
            ]);
        } catch (PermissionsNotGrantable $e) {
            throw ValidationException::withMessages([
                'permissions' => 'Você não pode retirar do seu próprio papel a permissão: '
                    .implode(', ', $e->names).'. Peça a outro administrador, para não perder o acesso a esta tela.',
            ]);
        }

        $redirect = to_route('admin.roles.permissions.edit', ['role' => $target->id]);

        if ($added === [] && $removed === []) {
            return $redirect->with('info', 'Nenhuma alteração para salvar.');
        }

        $count = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);

        return $redirect->with('success', "Permissões do papel {$target->name} salvas: "
            .$count(count($added), 'adicionada', 'adicionadas').' e '
            .$count(count($removed), 'removida', 'removidas').'.');
    }

    /** Papel consultado, com as permissões persistidas e o motivo quando for somente leitura. */
    private function selectedRole(Role $role, User $operator): array
    {
        $slugs = $role->permissions->pluck('slug')->sort()->values();

        $readOnlyReason = $operator->can('managePermissions', $role) ? null : 'not_grantable';

        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'is_system' => $role->is_system,
            'users_count' => $role->users()->count(),
            'permissions' => $slugs->all(),
            'version' => $role->permissionsVersion($slugs),
            'can_manage' => $readOnlyReason === null,
            'read_only_reason' => $readOnlyReason,
        ];
    }

    /**
     * Catálogo real, agrupado por módulo. `grantable` indica se o operador pode marcar ou desmarcar a
     * permissão; o backend verifica de novo ao salvar.
     *
     * @return list<array{key: string, label: string, permissions: list<array<string, mixed>>}>
     */
    private function catalog(User $operator): array
    {
        $labels = Permission::groupLabels();

        return Permission::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description'])
            ->groupBy(fn (Permission $permission) => $permission->group())
            ->sortBy(fn (Collection $items, string $group) => array_search($group, array_keys($labels), true))
            ->map(fn (Collection $items, string $group) => [
                'key' => $group,
                'label' => $labels[$group],
                'permissions' => $items->map(fn (Permission $permission) => [
                    'slug' => $permission->slug,
                    'name' => $permission->name,
                    'description' => $permission->description,
                    'administrative' => $permission->isAdministrative(),
                    'grantable' => true,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
