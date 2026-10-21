<?php

namespace App\Http\Controllers;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Requests\Tickets\TicketIndexRequest;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * CHA-01 — Listagem de chamados (RF-07).
     */
    public function index(TicketIndexRequest $request): Response
    {
        Gate::authorize('viewAny', Ticket::class);

        $user = $request->user();
        $filters = $request->filters();
        $sort = $request->sort();
        $direction = $request->direction();
        $now = now();

        // O escopo de acesso vem primeiro: filtros, ordenação, contagem e paginação atuam sobre ele.
        $tickets = Ticket::query()
            ->visibleTo($user)
            ->select([
                'tickets.id', 'tickets.ticket_number', 'tickets.title', 'tickets.requester_id',
                'tickets.department_id', 'tickets.category_id', 'tickets.assigned_to',
                'tickets.priority', 'tickets.status', 'tickets.resolution_due_at',
                'tickets.resolved_at', 'tickets.created_at', 'tickets.updated_at',
            ])
            ->with(['requester:id,name', 'assignee:id,name', 'department:id,name', 'category:id,name'])
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters, $now))
            ->tap(fn (Builder $query) => $this->applySort($query, $sort, $direction))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Ticket $ticket) => [
                'number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'requester' => $ticket->requester?->name,
                'department' => $ticket->department?->name,
                'category' => $ticket->category?->name,
                'priority' => $ticket->priority->label(),
                'status' => $ticket->status->label(),
                'assignee' => $ticket->assignee?->name,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
                'sla' => $ticket->slaState($now)->value,
            ]);

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'options' => $this->filterOptions($user),
            // Distingue "nenhum chamado acessível" de "nenhum resultado para os filtros".
            'hasAnyTicket' => $filters === [] ? $tickets->total() > 0 : Ticket::visibleTo($user)->exists(),
            'can' => [
                // A ação só aparece quando a abertura (CHA-02) existir e for autorizada.
                'create' => Route::has('tickets.create') && $user->can('create', Ticket::class),
            ],
        ]);
    }

    private function applyFilters(Builder $query, array $filters, CarbonInterface $now): void
    {
        $like = fn (string $value) => '%'.addcslashes($value, '%_\\').'%';

        if (isset($filters['q'])) {
            $query->where(fn (Builder $query) => $query
                ->where('tickets.ticket_number', 'like', $like(mb_strtoupper($filters['q'])))
                ->orWhere('tickets.title', 'like', $like($filters['q'])));
        }

        if (isset($filters['number'])) {
            $query->where('tickets.ticket_number', 'like', $like(mb_strtoupper($filters['number'])));
        }

        if (isset($filters['title'])) {
            $query->where('tickets.title', 'like', $like($filters['title']));
        }

        foreach (['requester' => 'requester_id', 'department' => 'department_id', 'category' => 'category_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where("tickets.$column", (int) $filters[$key]);
            }
        }

        if (isset($filters['priority'])) {
            $query->where('tickets.priority', $filters['priority']);
        }

        if (isset($filters['status'])) {
            $query->where('tickets.status', $filters['status']);
        }

        if (isset($filters['assignee'])) {
            $filters['assignee'] === 'none'
                ? $query->whereNull('tickets.assigned_to')
                : $query->where('tickets.assigned_to', (int) $filters['assignee']);
        }

        // As datas do filtro são dias completos no fuso da aplicação.
        if (isset($filters['opened_from'])) {
            $query->where('tickets.created_at', '>=', Carbon::parse($filters['opened_from'])->startOfDay());
        }

        if (isset($filters['opened_to'])) {
            $query->where('tickets.created_at', '<=', Carbon::parse($filters['opened_to'])->endOfDay());
        }

        if (isset($filters['sla'])) {
            $query->whereSlaState(SlaState::from($filters['sla']), $now);
        }
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        // Prioridade e status seguem a ordem de domínio dos enums, não a ordem alfabética.
        $rank = function (string $column, array $cases) use ($query, $direction) {
            $sql = "CASE $column ".str_repeat('WHEN ? THEN ? ', count($cases))."END $direction";
            $bindings = collect($cases)->flatMap(fn ($case, $i) => [$case->value, $i])->all();
            $query->orderByRaw($sql, $bindings);
        };

        match ($sort) {
            'number' => $query->orderBy('tickets.ticket_number', $direction),
            'title' => $query->orderBy('tickets.title', $direction),
            'priority' => $rank('tickets.priority', TicketPriority::cases()),
            'status' => $rank('tickets.status', TicketStatus::cases()),
            'assignee' => $query->orderBy(
                User::select('name')->whereColumn('users.id', 'tickets.assigned_to'),
                $direction,
            ),
            'created_at' => $query->orderBy('tickets.created_at', $direction),
            'updated_at' => $query->orderBy('tickets.updated_at', $direction),
        };

        // Critério de desempate estável para a paginação.
        $query->orderBy('tickets.id', $direction);
    }

    /**
     * Opções dos filtros, sempre a partir de dados reais. Solicitantes e responsáveis vêm
     * apenas dos chamados visíveis, para não revelar usuários fora do escopo.
     */
    private function filterOptions(User $user): array
    {
        $people = fn (string $column) => User::query()
            ->whereIn('id', Ticket::visibleTo($user)->whereNotNull("tickets.$column")->select("tickets.$column"))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $person) => ['value' => (string) $person->id, 'label' => $person->name])
            ->all();

        $catalog = fn (string $model) => $model::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn ($item) => [
                'value' => (string) $item->id,
                'label' => $item->is_active ? $item->name : "{$item->name} (inativo)",
            ])
            ->all();

        $enum = fn (array $cases) => array_map(
            fn ($case) => ['value' => $case->value, 'label' => $case->label()],
            $cases,
        );

        return [
            'statuses' => $enum(TicketStatus::cases()),
            'priorities' => $enum(TicketPriority::cases()),
            'slaStates' => $enum(SlaState::cases()),
            'departments' => $catalog(Department::class),
            'categories' => $catalog(TicketCategory::class),
            'requesters' => $people('requester_id'),
            'assignees' => $people('assigned_to'),
        ];
    }
}
