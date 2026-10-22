<?php

namespace App\Http\Controllers;

use App\Http\Requests\Permissions\PermissionIndexRequest;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    /** Mesmo tamanho de página das demais listagens administrativas. */
    private const PER_PAGE = 15;

    /**
     * PER-05 — Catálogo de permissões (RF-03). Somente consulta da tabela `permissions`, a mesma fonte
     * usada pela autorização (User::hasPermission()) e pela matriz (PER-04). O módulo é o prefixo do
     * identificador (Permission::GROUPS); não há coluna própria.
     */
    public function index(PermissionIndexRequest $request): Response
    {
        Gate::authorize('viewAny', Permission::class);

        $filters = $request->filters();
        $sort = $request->sort();
        $direction = $request->direction();
        $labels = Permission::groupLabels();

        $permissions = Permission::query()
            ->select(['permissions.id', 'permissions.name', 'permissions.slug', 'permissions.description'])
            ->withCount('roles')
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->orderBy("permissions.{$sort}", $direction)
            // Critério de desempate estável para a paginação.
            ->orderBy('permissions.id', $direction)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'slug' => $permission->slug,
                'description' => $permission->description,
                'group' => $permission->group(),
                'group_label' => $labels[$permission->group()],
                'administrative' => $permission->isAdministrative(),
                'roles_count' => $permission->roles_count,
            ]);

        return Inertia::render('Admin/Permissions/Index', [
            'permissions' => $permissions,
            'summary' => $this->summary(),
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'options' => [
                // Só os módulos que existem no catálogo: nada de categorias vazias.
                'modules' => collect($labels)
                    ->only($this->presentGroups())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
            ],
            'detail' => $this->detail($request->selected()),
            'selected' => $request->selected(),
        ]);
    }

    /**
     * Permissão aberta no painel. Nula quando não há seleção ou o identificador não existe (a tela
     * informa que a permissão não foi encontrada).
     */
    private function detail(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $permission = Permission::query()
            ->with(['roles' => fn ($query) => $query->orderBy('name')->select(['roles.id', 'roles.name', 'roles.is_system'])])
            ->find($id, ['id', 'name', 'slug', 'description']);

        if (! $permission) {
            return null;
        }

        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'slug' => $permission->slug,
            'description' => $permission->description,
            'group' => $permission->group(),
            'group_label' => Permission::groupLabels()[$permission->group()],
            'administrative' => $permission->isAdministrative(),
            // Papéis que a recebem hoje; não indica que um usuário específico pode executá-la.
            'roles' => $permission->roles
                ->map(fn ($role) => ['id' => $role->id, 'name' => $role->name, 'is_system' => $role->is_system])
                ->values()
                ->all(),
        ];
    }

    /**
     * Totais do catálogo inteiro, independentes da pesquisa e da página.
     *
     * @return array{total: int, modules: int, administrative: int}
     */
    private function summary(): array
    {
        return [
            'total' => Permission::count(),
            'modules' => count($this->presentGroups()),
            'administrative' => Permission::whereIn('slug', Permission::ADMINISTRATIVE)->count(),
        ];
    }

    /** @return list<string> Módulos com ao menos uma permissão, na ordem de exibição. */
    private function presentGroups(): array
    {
        $present = Permission::pluck('slug')
            ->map(fn (string $slug) => (new Permission(['slug' => $slug]))->group())
            ->unique();

        return array_values(array_filter(array_keys(Permission::groupLabels()), fn ($group) => $present->contains($group)));
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['q'])) {
            // `_` é comum nos identificadores (ex.: `self_assign`), então os curingas do LIKE precisam
            // ser literais. ESCAPE explícito, porque o SQLite (testes) não tem escape padrão como o MariaDB.
            $like = '%'.strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            // Módulos cujo nome corresponde à pesquisa (ex.: "chamados" encontra `tickets.*`).
            $term = Str::lower(Str::ascii($filters['q']));
            $groups = collect(Permission::GROUPS)
                ->filter(fn (string $label) => str_contains(Str::lower(Str::ascii($label)), $term))
                ->keys();

            $query->where(fn (Builder $query) => $query
                ->whereRaw("permissions.name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("permissions.slug LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("permissions.description LIKE ? ESCAPE '!'", [$like])
                ->when($groups->isNotEmpty(), fn (Builder $query) => $groups->each(
                    fn (string $group) => $query->orWhere('permissions.slug', 'like', $group.'.%'),
                )));
        }

        if (isset($filters['module'])) {
            $query->inGroup($filters['module']);
        }
    }
}
