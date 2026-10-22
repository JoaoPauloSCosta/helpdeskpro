<?php

namespace App\Http\Controllers;

use App\Actions\Categories\CategoryEditConflict;
use App\Actions\Categories\CreateCategory;
use App\Actions\Categories\UpdateCategory;
use App\Enums\AdminEvent;
use App\Http\Requests\Categories\CategoryIndexRequest;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Models\TicketCategory;
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

class CategoryController extends Controller
{
    /** Mesmo tamanho de página das demais listagens administrativas. */
    private const PER_PAGE = 15;

    /**
     * ADM-07 — Listagem de categorias (RF-04). Somente leitura: inativas continuam listadas para a
     * administração, ao contrário do catálogo de abertura de chamados, que só oferece as ativas.
     */
    public function index(CategoryIndexRequest $request): Response
    {
        Gate::authorize('viewAny', TicketCategory::class);

        $operator = $request->user();
        $filters = $request->filters();
        $sort = $request->sort();
        $direction = $request->direction();
        // A ação só aparece quando a tela de destino (ADM-09) existir.
        $editable = Route::has('admin.categories.edit');

        $categories = TicketCategory::query()
            ->select(['ticket_categories.id', 'ticket_categories.name', 'ticket_categories.description', 'ticket_categories.is_active', 'ticket_categories.updated_at'])
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->tap(fn (Builder $query) => $this->applySort($query, $sort, $direction))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (TicketCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'is_active' => $category->is_active,
                'updated_at' => $category->updated_at?->toIso8601String(),
                // Só a exibição da ação; o backend revalida ao abrir e ao salvar.
                'can_edit' => $editable && $operator->can('update', $category),
            ]);

        return Inertia::render('Admin/Categories/Index', [
            'categories' => $categories,
            'summary' => $this->summary(),
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'options' => ['statuses' => $this->statusOptions()],
            'can' => [
                'create' => Route::has('admin.categories.create') && $operator->can('create', TicketCategory::class),
            ],
        ]);
    }

    /**
     * ADM-08 — Criar categoria (RF-04).
     */
    public function create(): Response
    {
        Gate::authorize('create', TicketCategory::class);

        return Inertia::render('Admin/Categories/Create', [
            'statuses' => $this->formStatusOptions(),
            'limits' => $this->limits(),
            // Identifica o envio para que repetições da mesma requisição não dupliquem a categoria.
            'submissionToken' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreCategoryRequest $request, CreateCategory $createCategory, SingleSubmission $submission): RedirectResponse
    {
        $operator = $request->user();
        $data = $request->safe()->only(StoreCategoryRequest::FIELDS);

        try {
            [$id] = $submission->run(
                "categories:submission:{$operator->id}:{$request->validated('submission_token')}",
                fn () => $createCategory($operator, $data)->id,
            );
        } catch (SubmissionInProgress) {
            throw ValidationException::withMessages([
                'form' => 'O cadastro já está sendo registrado. Aguarde alguns instantes.',
            ]);
        }

        $category = TicketCategory::findOrFail($id);
        $situation = $category->is_active ? '' : ' como inativa';

        // A listagem abre filtrada na categoria recém-criada.
        return to_route('admin.categories.index', ['q' => $category->name])
            ->with('success', "Categoria {$category->name} cadastrada{$situation}.");
    }

    /**
     * ADM-09 — Editar categoria (RF-04). A permissão geral é verificada antes da busca, para que
     * quem não administra categorias não descubra quais identificadores existem.
     */
    public function edit(string $category): Response
    {
        Gate::authorize('viewAny', TicketCategory::class);

        $target = TicketCategory::withCount('tickets')->findOrFail($category);

        Gate::authorize('update', $target);

        return Inertia::render('Admin/Categories/Edit', [
            'category' => [
                'id' => $target->id,
                'name' => $target->name,
                'is_active' => $target->is_active,
                // Chamados preservados pela edição; ajudam a avaliar o efeito de uma inativação.
                'tickets_count' => $target->tickets_count,
                'created_at' => $target->created_at?->toIso8601String(),
                'updated_at' => $target->updated_at?->toIso8601String(),
            ],
            'values' => [
                'name' => $target->name,
                'description' => $target->description ?? '',
                'status' => $target->is_active ? 'active' : 'inactive',
            ],
            'statuses' => $this->formStatusOptions(),
            'limits' => $this->limits(),
            'version' => $target->editVersion(),
        ]);
    }

    public function update(UpdateCategoryRequest $request, UpdateCategory $updateCategory): RedirectResponse
    {
        $target = $request->target();

        try {
            $events = $updateCategory(
                $request->user(),
                $target,
                $request->safe()->only(['name', 'description', 'status']),
                $request->validated('version'),
            );
        } catch (CategoryEditConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'Esta categoria foi alterada por outra pessoa enquanto você editava. '
                    .'Recarregue os dados atuais antes de tentar novamente.',
            ]);
        }

        if ($events === []) {
            return to_route('admin.categories.edit', $target)->with('info', 'Nenhuma alteração para salvar.');
        }

        $message = match (true) {
            in_array(AdminEvent::CategoryDeactivated, $events, true) => "Categoria {$target->name} inativada. Os chamados vinculados foram preservados.",
            in_array(AdminEvent::CategoryActivated, $events, true) => "Categoria {$target->name} reativada.",
            default => "Alterações da categoria {$target->name} salvas.",
        };

        return to_route('admin.categories.index', ['q' => $target->name])->with('success', $message);
    }

    /** Situações oferecidas nos formulários de criação e edição. */
    private function formStatusOptions(): array
    {
        return collect(StoreCategoryRequest::STATUSES)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    private function limits(): array
    {
        return [
            'name' => TicketCategory::NAME_MAX_LENGTH,
            'description' => TicketCategory::DESCRIPTION_MAX_LENGTH,
        ];
    }

    /**
     * Totais de todas as categorias, independentes da pesquisa e da página: a administração não tem
     * escopo por departamento. Uma única consulta agregada.
     *
     * @return array{total: int, active: int, inactive: int}
     */
    private function summary(): array
    {
        $counts = TicketCategory::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_active THEN 1 ELSE 0 END), 0) AS active')
            ->first();

        $total = (int) $counts->total;
        $active = (int) $counts->active;

        return ['total' => $total, 'active' => $active, 'inactive' => $total - $active];
    }

    private function statusOptions(): array
    {
        return collect(CategoryIndexRequest::STATUSES)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['q'])) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';

            $query->where(fn (Builder $query) => $query
                ->where('ticket_categories.name', 'like', $like)
                ->orWhere('ticket_categories.description', 'like', $like));
        }

        if (isset($filters['status'])) {
            $query->where('ticket_categories.is_active', $filters['status'] === 'active');
        }
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            'name' => $query->orderBy('ticket_categories.name', $direction),
            'updated_at' => $query->orderBy('ticket_categories.updated_at', $direction),
        };

        // Critério de desempate estável para a paginação.
        $query->orderBy('ticket_categories.id', $direction);
    }
}
