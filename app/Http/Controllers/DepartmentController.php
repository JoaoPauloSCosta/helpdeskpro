<?php

namespace App\Http\Controllers;

use App\Actions\Departments\CreateDepartment;
use App\Actions\Departments\DepartmentEditConflict;
use App\Actions\Departments\UpdateDepartment;
use App\Enums\AdminEvent;
use App\Http\Requests\Departments\DepartmentIndexRequest;
use App\Http\Requests\Departments\StoreDepartmentRequest;
use App\Http\Requests\Departments\UpdateDepartmentRequest;
use App\Models\Department;
use App\Support\SingleSubmission;
use App\Support\SubmissionInProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /** Mesmo tamanho de página das demais listagens administrativas. */
    private const PER_PAGE = 15;

    /**
     * ADM-04 — Listagem de departamentos (RF-02). Somente leitura: inativos continuam listados para
     * preservar as referências históricas, e nada é alterado pela consulta.
     */
    public function index(DepartmentIndexRequest $request): Response
    {
        Gate::authorize('viewAny', Department::class);

        $operator = $request->user();
        $filters = $request->filters();
        $sort = $request->sort();
        $direction = $request->direction();

        $departments = Department::query()
            ->select(['departments.id', 'departments.name', 'departments.description', 'departments.is_active', 'departments.created_at', 'departments.updated_at'])
            // Vínculos reais (usuários e chamados históricos) contados na própria consulta.
            ->withCount(['users', 'tickets'])
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->tap(fn (Builder $query) => $this->applySort($query, $sort, $direction))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'description' => $department->description,
                'is_active' => $department->is_active,
                'users_count' => $department->users_count,
                'tickets_count' => $department->tickets_count,
                'created_at' => $department->created_at?->toIso8601String(),
                'updated_at' => $department->updated_at?->toIso8601String(),
                // Só a exibição da ação; o backend revalida ao abrir e ao salvar (ADM-06).
                'can_edit' => $operator->can('update', $department),
            ]);

        return Inertia::render('Admin/Departments/Index', [
            'departments' => $departments,
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'options' => ['statuses' => $this->statusOptions()],
            'can' => [
                // A ação só aparece quando a criação (ADM-05) existir e for autorizada.
                'create' => Route::has('admin.departments.create') && $operator->can('create', Department::class),
            ],
        ]);
    }

    /**
     * ADM-05 — Criar departamento (RF-02).
     */
    public function create(): Response
    {
        Gate::authorize('create', Department::class);

        return Inertia::render('Admin/Departments/Create', [
            'statuses' => $this->statusOptions(),
            'limits' => [
                'name' => Department::NAME_MAX_LENGTH,
                'description' => Department::DESCRIPTION_MAX_LENGTH,
            ],
            // Identifica o envio para que repetições da mesma requisição não dupliquem o departamento.
            'submissionToken' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreDepartmentRequest $request, CreateDepartment $createDepartment, SingleSubmission $submission): RedirectResponse
    {
        $operator = $request->user();
        $data = $request->safe()->only(StoreDepartmentRequest::FIELDS);

        try {
            [$id] = $submission->run(
                "departments:submission:{$operator->id}:{$request->validated('submission_token')}",
                fn () => $createDepartment($operator, $data)->id,
            );
        } catch (SubmissionInProgress) {
            throw ValidationException::withMessages([
                'form' => 'O cadastro já está sendo registrado. Aguarde alguns instantes.',
            ]);
        }

        $department = Department::findOrFail($id);
        $situation = $department->is_active ? '' : ' como inativo';

        // A listagem abre filtrada no departamento recém-criado.
        return to_route('admin.departments.index', ['q' => $department->name])
            ->with('success', "Departamento {$department->name} cadastrado{$situation}.");
    }

    /**
     * ADM-06 — Editar departamento (RF-02). A permissão geral é verificada antes da busca, para que
     * quem não administra departamentos não descubra quais identificadores existem.
     */
    public function edit(string $department): Response
    {
        Gate::authorize('viewAny', Department::class);

        $target = Department::withCount([
            'users',
            'users as active_users_count' => fn (Builder $query) => $query->where('is_active', true),
            'tickets',
        ])->findOrFail($department);

        Gate::authorize('update', $target);

        return Inertia::render('Admin/Departments/Edit', [
            'department' => [
                'id' => $target->id,
                'name' => $target->name,
                'is_active' => $target->is_active,
                // Vínculos preservados pela edição; ajudam a avaliar o efeito de uma inativação.
                'users_count' => $target->users_count,
                'active_users_count' => $target->active_users_count,
                'tickets_count' => $target->tickets_count,
                'created_at' => $target->created_at?->toIso8601String(),
                'updated_at' => $target->updated_at?->toIso8601String(),
            ],
            'values' => [
                'name' => $target->name,
                'description' => $target->description ?? '',
                'status' => $target->is_active ? 'active' : 'inactive',
            ],
            'statuses' => $this->statusOptions(),
            'limits' => [
                'name' => Department::NAME_MAX_LENGTH,
                'description' => Department::DESCRIPTION_MAX_LENGTH,
            ],
            'version' => $target->editVersion(),
        ]);
    }

    public function update(UpdateDepartmentRequest $request, UpdateDepartment $updateDepartment): RedirectResponse
    {
        $target = $request->target();

        try {
            $events = $updateDepartment(
                $request->user(),
                $target,
                $request->safe()->only(['name', 'description', 'status']),
                $request->validated('version'),
            );
        } catch (DepartmentEditConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'Este departamento foi alterado por outra pessoa enquanto você editava. '
                    .'Recarregue os dados atuais antes de tentar novamente.',
            ]);
        }

        if ($events === []) {
            return to_route('admin.departments.edit', $target)->with('info', 'Nenhuma alteração para salvar.');
        }

        $message = match (true) {
            in_array(AdminEvent::DepartmentDeactivated, $events, true) => "Departamento {$target->name} inativado. Os vínculos históricos foram preservados.",
            in_array(AdminEvent::DepartmentActivated, $events, true) => "Departamento {$target->name} reativado.",
            default => "Alterações do departamento {$target->name} salvas.",
        };

        return to_route('admin.departments.index', ['q' => $target->name])->with('success', $message);
    }

    private function statusOptions(): array
    {
        return collect(DepartmentIndexRequest::STATUSES)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['q'])) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';

            $query->where(fn (Builder $query) => $query
                ->where('departments.name', 'like', $like)
                ->orWhere('departments.description', 'like', $like));
        }

        if (isset($filters['status'])) {
            $query->where('departments.is_active', $filters['status'] === 'active');
        }
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            'name' => $query->orderBy('departments.name', $direction),
            'created_at' => $query->orderBy('departments.created_at', $direction),
            'updated_at' => $query->orderBy('departments.updated_at', $direction),
        };

        // Critério de desempate estável para a paginação.
        $query->orderBy('departments.id', $direction);
    }
}
