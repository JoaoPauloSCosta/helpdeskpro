<?php

namespace App\Http\Controllers;

use App\Enums\SlaState;
use App\Enums\TicketStatus;
use App\Http\Requests\PerformanceRequest;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\PerformanceReport;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PerformanceController extends Controller
{
    /**
     * Painel de desempenho dos técnicos (RF-25), somente leitura.
     *
     * Exige `reports.view_performance` e visão de todos os departamentos; ver o painel não autoriza
     * alterar chamados (RN-03). Os filtros são validados no servidor, o escopo de acesso vem de
     * `Ticket::visibleTo()` e o cálculo fica em `PerformanceReport`, que reaproveita as regras oficiais
     * de SLA em vez de criar outras.
     */
    public function index(PerformanceRequest $request): Response
    {
        Gate::authorize('viewPerformance');

        $user = $request->user();
        $filters = $request->filters();
        $now = now();
        $report = new PerformanceReport($user, $filters, $now);

        return Inertia::render('Performance/Index', [
            'filters' => $filters,
            'period' => $report->period(),
            'generatedAt' => $now->toIso8601String(),
            'report' => $report->build((int) ($request->validated('page') ?? 1)),
            'options' => $this->options($user),
        ]);
    }

    /**
     * Opções dos filtros. Os técnicos vêm apenas dos chamados visíveis, para não revelar usuários fora
     * do escopo.
     */
    private function options(User $user): array
    {
        $catalog = fn (string $model) => $model::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn ($item) => [
                'value' => (string) $item->id,
                'label' => $item->is_active ? $item->name : "{$item->name} (inativo)",
            ])
            ->all();

        return [
            'technicians' => User::query()
                ->whereIn('id', Ticket::visibleTo($user)->whereNotNull('tickets.assigned_to')->select('tickets.assigned_to'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $person) => ['value' => (string) $person->id, 'label' => $person->name])
                ->all(),
            'departments' => $catalog(Department::class),
            'categories' => $catalog(TicketCategory::class),
            'statuses' => array_map(fn (TicketStatus $s) => ['value' => $s->value, 'label' => $s->label()], TicketStatus::cases()),
            'slaStates' => array_map(fn (SlaState $s) => ['value' => $s->value, 'label' => $s->label()], SlaState::cases()),
        ];
    }
}
