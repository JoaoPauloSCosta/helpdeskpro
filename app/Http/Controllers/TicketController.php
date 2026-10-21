<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\OpenTicket;
use App\Actions\Tickets\TicketNumberUnavailable;
use App\Enums\CommentVisibility;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\TicketIndexRequest;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\TicketHistory;
use App\Models\User;
use App\Policies\TicketPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TicketController extends Controller
{
    private const PER_PAGE = 15;

    private const SUBMISSION_PENDING = 'pending';

    /** Por quanto tempo um envio repetido é reconhecido (segundos). */
    private const SUBMISSION_TTL = 3600;

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
        // Elegibilidade do técnico calculada uma vez; a do chamado vem de cada linha (CHA-06).
        $userMaySelfAssign = $user->isEligibleAssignee();

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
                // Só a exibição do botão; o backend revalida tudo ao assumir.
                'can_self_assign' => $userMaySelfAssign && TicketPolicy::isSelfAssignable($ticket),
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

    /**
     * CHA-02 — Abertura de chamado (RF-05).
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Ticket::class);

        $user = $request->user()->loadMissing('department:id,name,is_active');

        return Inertia::render('Tickets/Create', [
            'categories' => TicketCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (TicketCategory $category) => ['value' => (string) $category->id, 'label' => $category->name])
                ->all(),
            'priorities' => array_map(
                fn (TicketPriority $priority) => ['value' => $priority->value, 'label' => $priority->label()],
                TicketPriority::cases(),
            ),
            // Somente leitura: o autor e o departamento histórico são definidos pelo backend.
            'requester' => [
                'name' => $user->name,
                'email' => $user->email,
                'department' => $user->department?->name,
                'departmentActive' => (bool) $user->department?->is_active,
            ],
            'limits' => [
                'title' => Ticket::TITLE_MAX_LENGTH,
                'description' => Ticket::DESCRIPTION_MAX_LENGTH,
            ],
            // Identifica o envio para que repetições da mesma requisição não dupliquem o chamado.
            'submissionToken' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreTicketRequest $request, OpenTicket $openTicket): RedirectResponse
    {
        $user = $request->user();
        $key = "tickets:submission:{$user->id}:{$request->validated('submission_token')}";

        // Cache::add é atômico: só o primeiro envio com este token cria o chamado.
        if (! Cache::add($key, self::SUBMISSION_PENDING, self::SUBMISSION_TTL)) {
            $number = Cache::get($key);

            if (is_string($number) && $number !== self::SUBMISSION_PENDING) {
                return to_route('tickets.show', ['ticket' => $number])->with('ticketCreated', $number);
            }

            throw ValidationException::withMessages([
                'form' => 'Sua solicitação já está sendo registrada. Aguarde alguns instantes.',
            ]);
        }

        try {
            $ticket = $openTicket($user, $request->safe()->only(StoreTicketRequest::FIELDS));
        } catch (TicketNumberUnavailable) {
            Cache::forget($key);

            throw ValidationException::withMessages([
                'form' => 'Não foi possível registrar o chamado agora. Tente novamente em instantes.',
            ]);
        } catch (Throwable $e) {
            Cache::forget($key);

            throw $e;
        }

        Cache::put($key, $ticket->ticket_number, self::SUBMISSION_TTL);

        return to_route('tickets.show', $ticket)->with('ticketCreated', $ticket->ticket_number);
    }

    /**
     * CHA-03 — Detalhes do chamado. Nada é carregado antes da autorização, e comentários
     * passam pelo mesmo controle: notas internas são excluídas na consulta (RF-11).
     */
    public function show(Request $request, Ticket $ticket): Response
    {
        Gate::authorize('view', $ticket);

        $user = $request->user();
        $now = now();

        $ticket->load(['requester:id,name', 'assignee:id,name,department_id', 'assignee.department:id,name', 'department:id,name', 'category:id,name']);
        $canAssign = $user->can('assign', $ticket);
        $canReassign = $user->can('reassign', $ticket);
        // Independe do estado: após um conflito, o modal ainda mostra o responsável que venceu.
        $managesAssignment = $user->hasPermission('tickets.assign') || $user->hasPermission('tickets.reassign');

        $comments = $ticket->comments()
            ->visibleTo($user)
            ->with('author:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'ticket_id', 'user_id', 'body', 'visibility', 'created_at'])
            ->map(fn (TicketComment $comment) => [
                'id' => $comment->id,
                'author' => $comment->author?->name,
                'body' => $comment->body,
                'internal' => $comment->visibility === CommentVisibility::Internal,
                'created_at' => $comment->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Tickets/Show', [
            'ticket' => [
                'number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'requester' => $ticket->requester?->name,
                'department' => $ticket->department?->name,
                'category' => $ticket->category?->name,
                'priority' => $ticket->priority->label(),
                'status' => $ticket->status->label(),
                'assignee' => $ticket->assignee?->name,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
            ],
            // Somente instantes gravados pelo backend; sem motor de SLA, os prazos ficam nulos.
            'sla' => [
                'firstResponse' => [
                    'state' => $ticket->firstResponseSlaState($now)->value,
                    'due_at' => $ticket->first_response_due_at?->toIso8601String(),
                    'met_at' => $ticket->first_response_at?->toIso8601String(),
                ],
                'resolution' => [
                    'state' => $ticket->slaState($now)->value,
                    'due_at' => $ticket->resolution_due_at?->toIso8601String(),
                    'met_at' => $ticket->resolved_at?->toIso8601String(),
                ],
            ],
            'comments' => $comments,
            // Auditoria exige `audit.view` (RF-19); sem ela, nenhum evento é enviado.
            'history' => $user->hasPermission('audit.view') ? $this->history($ticket) : null,
            'can' => [
                'comment' => $user->can('comment', $ticket),
                'addInternalNote' => $user->can('addInternalNote', $ticket),
                'update' => $user->can('update', $ticket),
                'assign' => $canAssign,
                'reassign' => $canReassign,
                'selfAssign' => $user->can('selfAssign', $ticket),
            ],
            // Responsável atual para o modal de atribuição (CHA-05); só a quem pode operá-lo.
            'assignment' => $managesAssignment ? [
                'current' => $ticket->assignee ? [
                    'id' => $ticket->assignee->id,
                    'name' => $ticket->assignee->name,
                    'department' => $ticket->assignee->department?->name,
                ] : null,
            ] : null,
            // Carregado sob demanda quando o modal abre (recarga parcial do Inertia).
            'assignableTechnicians' => Inertia::optional(fn () => $managesAssignment
                ? User::eligibleAssignees()
                    ->whereKeyNot($ticket->assigned_to ?? 0)
                    ->with('department:id,name')
                    ->orderBy('name')
                    ->get(['users.id', 'users.name', 'users.department_id'])
                    ->map(fn (User $technician) => [
                        'id' => $technician->id,
                        'name' => $technician->name,
                        'department' => $technician->department?->name,
                    ])
                    ->all()
                : []),
            'limits' => ['comment' => TicketComment::BODY_MAX_LENGTH],
        ]);
    }

    /** Eventos registrados do chamado, em ordem cronológica. Nada é derivado do estado atual. */
    private function history(Ticket $ticket): array
    {
        return $ticket->histories()
            ->with('author:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TicketHistory $event) => [
                'id' => $event->id,
                'title' => $event->event_type->label(),
                'author' => $event->author?->name,
                'from' => $event->old_values['label'] ?? null,
                'to' => $event->new_values['label'] ?? null,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
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
