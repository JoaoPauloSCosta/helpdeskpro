<?php

namespace App\Http\Controllers;

use App\Actions\SlaPolicies\ActiveSlaPolicyConflict;
use App\Actions\SlaPolicies\CreateSlaPolicy;
use App\Actions\SlaPolicies\SlaPolicyEditConflict;
use App\Actions\SlaPolicies\UpdateSlaPolicy;
use App\Enums\AdminEvent;
use App\Enums\TicketPriority;
use App\Http\Requests\SlaPolicies\SlaPolicyIndexRequest;
use App\Http\Requests\SlaPolicies\StoreSlaPolicyRequest;
use App\Http\Requests\SlaPolicies\UpdateSlaPolicyRequest;
use App\Models\AuditLog;
use App\Models\SlaPolicy;
use App\Support\SingleSubmission;
use App\Support\SubmissionInProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SlaPolicyController extends Controller
{
    /** Mesmo tamanho de página das demais listagens administrativas. */
    private const PER_PAGE = 15;

    /**
     * SLA-01 — Listagem de políticas de SLA (RF-15). Somente leitura: inativas continuam listadas,
     * porque preservam metas já aplicadas. A situação é a cadastral da política, não o cumprimento
     * do SLA de algum chamado.
     */
    public function index(SlaPolicyIndexRequest $request): Response
    {
        Gate::authorize('viewAny', SlaPolicy::class);

        $filters = $request->filters();
        $sort = $request->sort();
        $direction = $request->direction();
        // As ações só aparecem quando as telas de destino (SLA-04 e SLA-03) existem.
        $viewable = Route::has('sla-policies.show');
        $editable = Route::has('sla-policies.edit');
        $operator = $request->user();

        $policies = SlaPolicy::query()
            ->select(['id', 'priority', 'first_response_minutes', 'resolution_minutes', 'is_active', 'updated_at'])
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->tap(fn (Builder $query) => $this->applySort($query, $sort, $direction))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (SlaPolicy $policy) => [
                'id' => $policy->id,
                'priority' => $policy->priority->label(),
                'first_response_minutes' => $policy->first_response_minutes,
                'resolution_minutes' => $policy->resolution_minutes,
                'is_active' => $policy->is_active,
                'updated_at' => $policy->updated_at?->toIso8601String(),
                // Só a exibição das ações; o backend revalida ao abrir e ao salvar.
                'can_view' => $viewable && $operator->can('view', $policy),
                'can_edit' => $editable && $operator->can('update', $policy),
            ]);

        return Inertia::render('SlaPolicies/Index', [
            'policies' => $policies,
            'summary' => $this->summary(),
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'options' => [
                'priorities' => collect(TicketPriority::cases())
                    ->map(fn (TicketPriority $priority) => ['value' => $priority->value, 'label' => $priority->label()])
                    ->all(),
                'statuses' => collect(SlaPolicyIndexRequest::STATUSES)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
            ],
            'can' => [
                'create' => Route::has('sla-policies.create') && $request->user()->can('create', SlaPolicy::class),
            ],
        ]);
    }

    /**
     * SLA-02 — Criar política de SLA (RF-15.3). As prioridades vêm do enum do domínio, a mesma
     * fonte dos chamados; cada uma informa se já tem política ativa, para a tela orientar a escolha
     * da situação antes do envio (o backend valida de novo).
     */
    public function create(): Response
    {
        Gate::authorize('create', SlaPolicy::class);

        $withActive = SlaPolicy::where('is_active', true)->toBase()->distinct()->pluck('priority');

        return Inertia::render('SlaPolicies/Create', [
            'priorities' => collect(TicketPriority::cases())
                ->map(fn (TicketPriority $priority) => [
                    'value' => $priority->value,
                    'label' => $priority->label(),
                    'has_active' => $withActive->contains($priority->value),
                ])
                ->all(),
            'statuses' => collect(StoreSlaPolicyRequest::STATUSES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'limits' => ['min' => SlaPolicy::TARGET_MIN_MINUTES, 'max' => SlaPolicy::TARGET_MAX_MINUTES],
            // Identifica o envio para que repetições da mesma requisição não dupliquem a política.
            'submissionToken' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreSlaPolicyRequest $request, CreateSlaPolicy $createSlaPolicy, SingleSubmission $submission): RedirectResponse
    {
        $operator = $request->user();
        $data = $request->safe()->only(StoreSlaPolicyRequest::FIELDS);

        try {
            [$id] = $submission->run(
                "sla-policies:submission:{$operator->id}:{$request->validated('submission_token')}",
                fn () => $createSlaPolicy($operator, $data)->id,
            );
        } catch (SubmissionInProgress) {
            throw ValidationException::withMessages([
                'form' => 'O cadastro já está sendo registrado. Aguarde alguns instantes.',
            ]);
        } catch (ActiveSlaPolicyConflict) {
            throw ValidationException::withMessages([
                'status' => StoreSlaPolicyRequest::activeConflictMessage(TicketPriority::from($data['priority'])),
            ]);
        }

        $policy = SlaPolicy::findOrFail($id);
        $situation = $policy->is_active ? 'ativa' : 'como inativa';

        // A listagem abre filtrada na prioridade da política recém-criada.
        return to_route('sla-policies.index', ['priority' => $policy->priority->value])
            ->with('success', "Política de SLA da prioridade {$policy->priority->label()} cadastrada {$situation}.");
    }

    /**
     * SLA-04 — Detalhes da política de SLA (RF-15.3). Somente consulta. A permissão geral é verificada
     * antes da busca, para que quem não administra SLA não descubra quais identificadores existem.
     */
    public function show(Request $request, string $policy): Response
    {
        Gate::authorize('viewAny', SlaPolicy::class);

        $target = SlaPolicy::findOrFail($policy);

        Gate::authorize('view', $target);

        $operator = $request->user();
        // A política ativa da prioridade, quando não é esta (no máximo uma, garantido pelo banco).
        $activeSibling = $target->is_active ? null : SlaPolicy::query()
            ->where('priority', $target->priority->value)
            ->where('is_active', true)
            ->first(['id', 'first_response_minutes', 'resolution_minutes']);

        return Inertia::render('SlaPolicies/Show', [
            'policy' => [
                'id' => $target->id,
                'priority' => $target->priority->label(),
                'is_active' => $target->is_active,
                'first_response_minutes' => $target->first_response_minutes,
                'resolution_minutes' => $target->resolution_minutes,
                'created_at' => $target->created_at?->toIso8601String(),
                'updated_at' => $target->updated_at?->toIso8601String(),
            ],
            'activeSibling' => $activeSibling ? [
                'id' => $activeSibling->id,
                'first_response_minutes' => $activeSibling->first_response_minutes,
                'resolution_minutes' => $activeSibling->resolution_minutes,
            ] : null,
            // Nulo sem `audit.view`: a tela omite a seção em vez de mostrá-la vazia.
            'history' => $operator->can('viewHistory', $target) ? $this->history($target) : null,
            'can' => [
                'edit' => Route::has('sla-policies.edit') && $operator->can('update', $target),
            ],
        ]);
    }

    /**
     * Eventos de auditoria da política (RF-19), do mais recente ao mais antigo, com o autor carregado
     * junto (sem N+1). Só os campos da política; a prioridade, repetida em cada evento, fica de fora.
     */
    private function history(SlaPolicy $policy): array
    {
        return $policy->auditLogs()
            ->with('author:id,name')
            ->latest('created_at')
            ->latest('id')
            ->get(['id', 'user_id', 'event_type', 'old_values', 'new_values', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event_type->value,
                'title' => $log->event_type->label(),
                'author' => $log->author?->name,
                'created_at' => $log->created_at?->toIso8601String(),
                'changes' => collect(['first_response_minutes', 'resolution_minutes', 'is_active'])
                    ->filter(fn (string $field) => array_key_exists($field, $log->new_values ?? []))
                    ->map(fn (string $field) => [
                        'field' => $field,
                        'from' => $log->old_values[$field] ?? null,
                        'to' => $log->new_values[$field],
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * SLA-03 — Editar política de SLA (RF-15.3): metas e situação. A permissão geral é verificada
     * antes da busca, para que quem não administra SLA não descubra quais identificadores existem.
     */
    public function edit(string $policy): Response
    {
        Gate::authorize('viewAny', SlaPolicy::class);

        $target = SlaPolicy::findOrFail($policy);

        Gate::authorize('update', $target);

        return Inertia::render('SlaPolicies/Edit', [
            'policy' => [
                'id' => $target->id,
                'priority' => $target->priority->label(),
                'is_active' => $target->is_active,
                'first_response_minutes' => $target->first_response_minutes,
                'resolution_minutes' => $target->resolution_minutes,
                // Outra política ativa na mesma prioridade impede ativar esta (o backend valida de novo).
                'other_active' => SlaPolicy::hasActiveFor($target->priority, $target->id),
                'created_at' => $target->created_at?->toIso8601String(),
                'updated_at' => $target->updated_at?->toIso8601String(),
            ],
            'values' => [
                'first_response_minutes' => (string) $target->first_response_minutes,
                'resolution_minutes' => (string) $target->resolution_minutes,
                'status' => $target->is_active ? 'active' : 'inactive',
            ],
            'statuses' => collect(StoreSlaPolicyRequest::STATUSES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'limits' => ['min' => SlaPolicy::TARGET_MIN_MINUTES, 'max' => SlaPolicy::TARGET_MAX_MINUTES],
            'version' => $target->editVersion(),
        ]);
    }

    public function update(UpdateSlaPolicyRequest $request, UpdateSlaPolicy $updateSlaPolicy): RedirectResponse
    {
        $target = $request->target();

        try {
            $events = $updateSlaPolicy(
                $request->user(),
                $target,
                $request->safe()->only(UpdateSlaPolicyRequest::FIELDS),
                $request->validated('version'),
            );
        } catch (SlaPolicyEditConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'Esta política foi alterada por outra pessoa enquanto você editava. '
                    .'Recarregue os dados atuais antes de tentar novamente.',
            ]);
        } catch (ActiveSlaPolicyConflict) {
            throw ValidationException::withMessages([
                'status' => UpdateSlaPolicyRequest::activationConflictMessage($target),
            ]);
        }

        if ($events === []) {
            return to_route('sla-policies.edit', $target)->with('info', 'Nenhuma alteração para salvar.');
        }

        $label = $target->priority->label();
        $message = match (true) {
            in_array(AdminEvent::SlaPolicyDeactivated, $events, true) => "Política de SLA da prioridade {$label} inativada.",
            in_array(AdminEvent::SlaPolicyActivated, $events, true) => "Política de SLA da prioridade {$label} ativada.",
            default => "Metas da política de SLA da prioridade {$label} atualizadas.",
        };

        return to_route('sla-policies.index', ['priority' => $target->priority->value])->with('success', $message);
    }

    /**
     * Totais de todas as políticas, independentes dos filtros e da página. A cobertura conta as
     * prioridades com política ativa (no máximo uma por prioridade, garantido pelo banco).
     *
     * @return array{total: int, active: int, inactive: int, priorities: int, covered: int}
     */
    private function summary(): array
    {
        $counts = SlaPolicy::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_active THEN 1 ELSE 0 END), 0) AS active')
            ->first();

        $withActive = SlaPolicy::where('is_active', true)->toBase()->distinct()->pluck('priority');
        $priorities = collect(TicketPriority::cases());
        $total = (int) $counts->total;
        $active = (int) $counts->active;

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'priorities' => $priorities->count(),
            'covered' => $priorities->filter(fn (TicketPriority $p) => $withActive->contains($p->value))->count(),
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            // Ordem de domínio do enum (Baixa → Crítica), não a alfabética, como na listagem de chamados.
            'priority' => $query->orderByRaw(
                'CASE priority '.str_repeat('WHEN ? THEN ? ', count(TicketPriority::cases()))."END $direction",
                collect(TicketPriority::cases())->flatMap(fn (TicketPriority $p, int $i) => [$p->value, $i])->all(),
            ),
            'first_response_minutes', 'resolution_minutes', 'updated_at' => $query->orderBy($sort, $direction),
        };

        // Critério de desempate estável para a paginação.
        $query->orderBy('id', $direction);
    }
}
