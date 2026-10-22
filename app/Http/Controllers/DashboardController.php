<?php

namespace App\Http\Controllers;

use App\Enums\SlaState;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Requests\DashboardRequest;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketSlaPause;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Itens exibidos nos rankings de departamento e de técnico; os demais são somados. */
    private const RANKING_SIZE = 8;

    /**
     * IND-01 — Dashboard operacional (RF-20). Somente leitura.
     *
     * Todos os indicadores partem do mesmo escopo da listagem (Ticket::scopeVisibleTo()), aplicado
     * antes do período e de qualquer agregação: um chamado que o usuário não consulta na CHA-01
     * não entra em nenhum total, gráfico ou ranking. O período seleciona os chamados abertos nele;
     * os status e prioridades são os atuais desses chamados.
     *
     * Não calculados (dependem do motor de SLA, ainda inexistente: os prazos e `first_response_at`
     * não são gravados): tempo médio de primeira resposta e tempo efetivo médio de resolução.
     */
    public function index(DashboardRequest $request): Response
    {
        Gate::authorize('viewAny', Ticket::class);

        $user = $request->user();
        $period = $request->filters();
        $now = now();

        $tickets = fn (): Builder => Ticket::query()
            ->visibleTo($user)
            ->tap(fn (Builder $query) => $this->applyPeriod($query, $period));

        // Uma consulta agrupada fornece o total e a distribuição por status.
        $byStatus = $tickets()->toBase()
            ->select('tickets.status')
            ->selectRaw('count(*) as total')
            ->groupBy('tickets.status')
            ->pluck('total', 'status');

        $total = (int) $byStatus->sum();

        return Inertia::render('Dashboard', [
            'filters' => $period,
            'generatedAt' => $now->toIso8601String(),
            // Só descreve na tela o escopo aplicado por scopeVisibleTo(); não autoriza nada.
            'scope' => [
                'allDepartments' => $user->hasPermission('tickets.view_all_departments'),
                'includesAvailable' => $user->hasPermission('tickets.self_assign'),
            ],
            // Distingue "nenhum chamado acessível" de "nenhum chamado aberto no período".
            'hasAnyTicket' => $period === [] ? $total > 0 : Ticket::visibleTo($user)->exists(),
            'can' => [
                // Ação sugerida no estado vazio, como na listagem.
                'create' => Route::has('tickets.create') && $user->can('create', Ticket::class),
            ],
            'metrics' => $total === 0 ? null : [
                'total' => $total,
                'byStatus' => $this->distribution(TicketStatus::cases(), $byStatus),
                'byPriority' => $this->distribution(
                    TicketPriority::cases(),
                    $tickets()->toBase()
                        ->select('tickets.priority')
                        ->selectRaw('count(*) as total')
                        ->groupBy('tickets.priority')
                        ->pluck('total', 'priority'),
                ),
                'byDepartment' => $this->ranking(
                    $tickets()->toBase()
                        ->select('tickets.department_id as key')
                        ->selectRaw('count(*) as total')
                        ->groupBy('tickets.department_id')
                        ->get(),
                    fn (array $ids) => Department::whereKey($ids)->pluck('name', 'id'),
                ),
                'byAssignee' => $this->ranking(
                    $tickets()->toBase()
                        ->select('tickets.assigned_to as key')
                        ->selectRaw('count(*) as total')
                        ->groupBy('tickets.assigned_to')
                        ->get(),
                    fn (array $ids) => User::whereKey($ids)->pluck('name', 'id'),
                    unassigned: 'Sem responsável',
                ),
                'sla' => [
                    // Mesma regra do filtro "Vencido" da listagem: em andamento com o prazo de
                    // resolução vencido, ou resolvido depois dele.
                    'overdue' => $tickets()->whereSlaState(SlaState::Overdue, $now)->count(),
                    // Sem nenhum prazo gravado, o zero acima não é um resultado: o SLA não foi apurado.
                    'tracked' => $tickets()->whereNotNull('tickets.resolution_due_at')->count(),
                ],
                // Chamados com ao menos uma reabertura registrada (RF-13), não o número de reaberturas.
                'reopened' => TicketHistory::query()
                    ->where('event_type', TicketEvent::Reopened)
                    ->whereIn('ticket_id', $tickets()->select('tickets.id'))
                    ->distinct()
                    ->count('ticket_id'),
                'pending' => $this->pending($tickets()),
            ],
        ]);
    }

    /** Mesmo critério do filtro de abertura da listagem: dias completos no fuso da aplicação. */
    private function applyPeriod(Builder $query, array $period): void
    {
        if (isset($period['opened_from'])) {
            $query->where('tickets.created_at', '>=', Carbon::parse($period['opened_from'])->startOfDay());
        }

        if (isset($period['opened_to'])) {
            $query->where('tickets.created_at', '<=', Carbon::parse($period['opened_to'])->endOfDay());
        }
    }

    /**
     * Contagem por valor de um enum, com todos os casos na ordem de domínio (inclusive os zerados).
     *
     * @param  list<TicketStatus|TicketPriority>  $cases
     */
    private function distribution(array $cases, Collection $counts): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'total' => (int) ($counts[$case->value] ?? 0),
        ], $cases);
    }

    /**
     * Ranking decrescente (empates em ordem alfabética). Os nomes são buscados só para as chaves
     * presentes nos chamados visíveis, sem revelar cadastros fora do escopo. Além dos primeiros
     * itens, informa quantos ficaram de fora e quantos chamados eles somam.
     *
     * @param  callable(list<int>): Collection  $names
     */
    private function ranking(Collection $rows, callable $names, ?string $unassigned = null): array
    {
        $labels = $names($rows->pluck('key')->filter()->values()->all());

        $items = $rows
            ->map(fn (object $row) => [
                // `none` é o valor do filtro "Não atribuído" da listagem.
                'value' => $row->key === null ? 'none' : (string) $row->key,
                'label' => $row->key === null ? $unassigned : ($labels[$row->key] ?? '—'),
                'total' => (int) $row->total,
            ])
            ->sort(fn (array $a, array $b) => [$b['total'], $a['label']] <=> [$a['total'], $b['label']])
            ->values();

        $rest = $items->slice(self::RANKING_SIZE);

        return [
            'items' => $items->take(self::RANKING_SIZE)->all(),
            'others' => $rest->isEmpty() ? null : ['count' => $rest->count(), 'total' => $rest->sum('total')],
        ];
    }

    /**
     * Tempo em pendência (RF-17): soma das durações gravadas nas retomadas. Pausas em andamento
     * ainda não têm duração e são apenas contadas, como no histórico de SLA (CHA-13).
     */
    private function pending(Builder $tickets): array
    {
        $pauses = TicketSlaPause::query()
            ->whereIn('ticket_id', $tickets->select('tickets.id'))
            ->toBase()
            ->selectRaw('count(ended_at) as closed')
            ->selectRaw('coalesce(sum(case when ended_at is not null then duration_seconds end), 0) as closed_seconds')
            ->selectRaw('count(*) - count(ended_at) as active')
            ->first();

        return [
            'closed' => (int) $pauses->closed,
            'closed_seconds' => (int) $pauses->closed_seconds,
            'active' => (int) $pauses->active,
        ];
    }
}
