<?php

namespace App\Http\Controllers;

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\RoleEditConflict;
use App\Actions\Roles\UpdateRole;
use App\Http\Requests\Roles\RoleIndexRequest;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /** Mesmo tamanho de página das demais listagens administrativas. */
    private const PER_PAGE = 15;

    /**
     * PER-01 — Listagem de papéis (RF-03). Somente leitura; a matriz de permissões (PER-04) ainda
     * não existe, por isso a única ação das linhas é a edição cadastral (PER-03).
     */
    public function index(RoleIndexRequest $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        $operator = $request->user();
        $filters = $request->filters();
        $sort = $request->sort();
        $direction = $request->direction();
        // A ação só aparece quando a tela de destino (PER-03) existir.
        $editable = Route::has('admin.roles.edit');

        $roles = Role::query()
            ->select(['roles.id', 'roles.name', 'roles.slug', 'roles.description', 'roles.is_system'])
            // Vínculos reais (usuários e permissões) contados na própria consulta, sem N+1.
            ->withCount(['users', 'permissions'])
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->tap(fn (Builder $query) => $this->applySort($query, $sort, $direction))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
                // Só a exibição da ação; o backend revalida ao abrir e ao salvar.
                'can_edit' => $editable && $operator->can('update', $role),
            ]);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles,
            'summary' => $this->summary(),
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'can' => [
                // A ação só aparece quando a criação (PER-02) existir e for autorizada.
                'create' => Route::has('admin.roles.create') && $operator->can('create', Role::class),
                // Consultar a matriz (PER-04) só exige o acesso a esta listagem.
                'matrix' => Route::has('admin.roles.permissions.edit'),
                // O catálogo (PER-05) usa a mesma permissão desta listagem.
                'catalog' => Route::has('admin.permissions.index'),
            ],
        ]);
    }

    /**
     * PER-02 — Criar papel (RF-03). Só nome e descrição: as permissões são associadas na matriz
     * (PER-04), e o papel criado não concede nenhum acesso até lá.
     */
    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('Admin/Roles/Create', [
            'limits' => [
                'name' => Role::NAME_MAX_LENGTH,
                'description' => Role::DESCRIPTION_MAX_LENGTH,
            ],
        ]);
    }

    public function store(StoreRoleRequest $request, CreateRole $createRole): RedirectResponse
    {
        try {
            $role = $createRole(
                $request->user(),
                $request->safe()->only(StoreRoleRequest::FIELDS),
                $request->slug(),
            );
        } catch (UniqueConstraintViolationException) {
            // Envio simultâneo com nome equivalente: a restrição do slug no banco é a garantia final.
            throw ValidationException::withMessages(['name' => 'Já existe um papel com um nome equivalente.']);
        }

        // A listagem abre filtrada no papel recém-criado.
        return to_route('admin.roles.index', ['q' => $role->name])
            ->with('success', "Papel {$role->name} cadastrado sem permissões associadas.");
    }

    /**
     * PER-03 — Editar papel (RF-03): nome e descrição. A permissão geral é verificada antes da busca,
     * para que quem não administra papéis não descubra quais identificadores existem.
     */
    public function edit(string $role): Response
    {
        Gate::authorize('viewAny', Role::class);

        $target = Role::withCount(['users', 'permissions'])->findOrFail($role);

        Gate::authorize('update', $target);

        return Inertia::render('Admin/Roles/Edit', [
            'role' => [
                'id' => $target->id,
                'name' => $target->name,
                'slug' => $target->slug,
                'is_system' => $target->is_system,
                // Vínculos preservados pela edição, exibidos como contexto.
                'users_count' => $target->users_count,
                'permissions_count' => $target->permissions_count,
                'created_at' => $target->created_at?->toIso8601String(),
                'updated_at' => $target->updated_at?->toIso8601String(),
            ],
            'values' => [
                'name' => $target->name,
                'description' => $target->description ?? '',
            ],
            'limits' => [
                'name' => Role::NAME_MAX_LENGTH,
                'description' => Role::DESCRIPTION_MAX_LENGTH,
            ],
            'version' => $target->editVersion(),
        ]);
    }

    public function update(UpdateRoleRequest $request, UpdateRole $updateRole): RedirectResponse
    {
        $target = $request->target();

        try {
            $changed = $updateRole(
                $request->user(),
                $target,
                $request->safe()->only(StoreRoleRequest::FIELDS),
                $request->validated('version'),
            );
        } catch (RoleEditConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'Este papel foi alterado por outra pessoa enquanto você editava. '
                    .'Recarregue os dados atuais antes de tentar novamente.',
            ]);
        }

        if (! $changed) {
            return to_route('admin.roles.edit', $target)->with('info', 'Nenhuma alteração para salvar.');
        }

        return to_route('admin.roles.index', ['q' => $target->name])
            ->with('success', "Alterações do papel {$target->name} salvas. Permissões e usuários vinculados foram mantidos.");
    }

    /**
     * Totais de todos os papéis, independentes da pesquisa e da página.
     *
     * @return array{total: int, system: int, users: int}
     */
    private function summary(): array
    {
        $counts = Role::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_system THEN 1 ELSE 0 END), 0) AS `system`')
            ->first();

        return [
            'total' => (int) $counts->total,
            'system' => (int) $counts->system,
            // Usuários (ativos e inativos) vinculados a algum papel.
            'users' => User::whereNotNull('role_id')->count(),
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['q'])) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';

            $query->where(fn (Builder $query) => $query
                ->where('roles.name', 'like', $like)
                ->orWhere('roles.description', 'like', $like));
        }
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            'name' => $query->orderBy('roles.name', $direction),
            'users_count' => $query->orderBy('users_count', $direction)->orderBy('roles.name'),
        };

        // Critério de desempate estável para a paginação.
        $query->orderBy('roles.id', $direction);
    }
}
