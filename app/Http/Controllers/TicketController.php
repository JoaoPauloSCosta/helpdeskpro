<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\OpenTicket;
use App\Actions\Tickets\TicketNumberUnavailable;
use App\Enums\CommentVisibility;
use App\Enums\SlaState;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Requests\Concerns\Attachments;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\TicketIndexRequest;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\TicketSlaPause;
use App\Models\User;
use App\Policies\TicketPolicy;
use App\Support\SlaClock;
use App\Support\TicketListingQuery;
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
        // Visão restrita do solicitante (RF-24): sem solicitante, prioridade e SLA nos dados enviados.
        $restricted = $user->hasRestrictedTicketView();

        // O escopo de acesso vem primeiro: filtros, ordenação, contagem e paginação atuam sobre ele.
        $tickets = Ticket::query()
            ->visibleTo($user)
            ->select([
                'tickets.id', 'tickets.ticket_number', 'tickets.title', 'tickets.requester_id',
                'tickets.department_id', 'tickets.category_id', 'tickets.assigned_to',
                'tickets.priority', 'tickets.status', 'tickets.resolution_due_at', 'tickets.sla_resolution_minutes',
                'tickets.resolved_at', 'tickets.created_at', 'tickets.updated_at',
            ])
            ->with(['requester:id,name', 'assignee:id,name', 'department:id,name', 'category:id,name'])
            ->tap(fn (Builder $query) => TicketListingQuery::filter($query, $filters, $now))
            ->tap(fn (Builder $query) => TicketListingQuery::sort($query, $sort, $direction))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Ticket $ticket) => [
                // Na visão restrita o identificador só monta o link; a tela não o exibe.
                'number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'department' => $ticket->department?->name,
                'category' => $ticket->category?->name,
                'status' => $ticket->status->label(),
                'assignee' => $ticket->assignee?->name,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
                // Só a exibição do botão; o backend revalida tudo ao assumir.
                'can_self_assign' => $userMaySelfAssign && TicketPolicy::isSelfAssignable($ticket),
                ...($restricted ? [] : [
                    'requester' => $ticket->requester?->name,
                    'priority' => $ticket->priority->label(),
                    'sla' => $ticket->slaState($now)->value,
                ]),
            ]);

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'options' => $this->filterOptions($user, $restricted),
            'restricted' => $restricted,
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
            'attachmentLimits' => $this->attachmentLimits(),
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
            $ticket = $openTicket($user, $request->safe()->only(StoreTicketRequest::FIELDS), Attachments::files($request));
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
        // Idem para a alteração de status (CHA-07): após um conflito, o modal mostra o status que venceu.
        $managesStatus = $user->hasPermission('tickets.change_status');
        // Idem para a resolução (CHA-09).
        $resolves = $user->hasPermission('tickets.resolve');
        // Idem para a reabertura (CHA-10), que depende da propriedade do chamado e não de permissão.
        $ownsTicket = TicketPolicy::isOriginalRequester($user, $ticket);
        // Idem para o encerramento (CHA-11).
        $closes = $user->hasPermission('tickets.close');
        $restricted = $user->hasRestrictedTicketView();

        $comments = $ticket->comments()
            ->visibleTo($user)
            ->select(['id', 'ticket_id', 'user_id', 'body', 'visibility', 'created_at'])
            // Os anexos vêm das mensagens que o usuário pode ler: o anexo herda a visibilidade (RF-23).
            ->with(['author:id,name', 'attachments:id,ticket_comment_id,original_name,mime_type,size_bytes'])
            // Versões anteriores que o usuário pode ler (CHA-12); o conteúdo vem só sob demanda.
            ->withCount(['versions' => fn (Builder $query) => $query->visibleTo($user)])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TicketComment $comment) => [
                'id' => $comment->id,
                'author' => $comment->author?->name,
                'body' => $comment->body,
                'internal' => $comment->visibility === CommentVisibility::Internal,
                'created_at' => $comment->created_at?->toIso8601String(),
                'edited' => $comment->versions_count > 0,
                'attachments' => $comment->attachments->map(fn (TicketAttachment $a) => $this->attachmentPayload($a))->all(),
            ]);

        return Inertia::render('Tickets/Show', [
            'ticket' => [
                'number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'department' => $ticket->department?->name,
                'category' => $ticket->category?->name,
                'status' => $ticket->status->label(),
                'assignee' => $ticket->assignee?->name,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
                // Prioridade e solicitante ficam fora da visão restrita do solicitante (RF-24).
                ...($restricted ? [] : [
                    'requester' => $ticket->requester?->name,
                    'priority' => $ticket->priority->label(),
                ]),
            ],
            // Calculado no backend (SlaClock) a partir dos instantes gravados; sem política, tudo nulo.
            // `remaining_seconds` negativo: vencido há esse tempo. `breached_at` é o histórico (RN-15),
            // independente da situação atual.
            'sla' => $restricted ? null : [
                'firstResponse' => [
                    'state' => $ticket->firstResponseSlaState($now)->value,
                    'due_at' => $ticket->first_response_due_at?->toIso8601String(),
                    'met_at' => $ticket->first_response_at?->toIso8601String(),
                    'target_minutes' => $ticket->sla_first_response_minutes,
                    'remaining_seconds' => $ticket->first_response_due_at
                        ? $ticket->first_response_due_at->getTimestamp() - ($ticket->first_response_at ?? $now)->getTimestamp()
                        : null,
                    'breached_at' => $ticket->first_response_breached_at?->toIso8601String(),
                ],
                'resolution' => [
                    'state' => $ticket->slaState($now)->value,
                    'due_at' => $ticket->resolution_due_at?->toIso8601String(),
                    // Depois de uma reabertura, a resolução anterior não conta como meta atingida.
                    'met_at' => in_array($ticket->status, TicketStatus::finished(), true)
                        ? $ticket->resolved_at?->toIso8601String()
                        : null,
                    'target_minutes' => $ticket->sla_resolution_minutes,
                    'remaining_seconds' => SlaClock::remainingResolutionSeconds($ticket, $now),
                    'breached_at' => $ticket->resolution_breached_at?->toIso8601String(),
                ],
            ],
            'comments' => $comments,
            // Anexos da abertura (públicos).
            'attachments' => $ticket->attachments()
                ->whereNull('ticket_comment_id')
                ->orderBy('id')
                ->get(['id', 'original_name', 'mime_type', 'size_bytes'])
                ->map(fn (TicketAttachment $a) => $this->attachmentPayload($a))
                ->all(),
            'attachmentLimits' => $this->attachmentLimits(),
            'can' => [
                'comment' => $user->can('comment', $ticket),
                'addInternalNote' => $user->can('addInternalNote', $ticket),
                'update' => $user->can('update', $ticket),
                'assign' => $canAssign,
                'reassign' => $canReassign,
                'selfAssign' => $user->can('selfAssign', $ticket),
                'changeStatus' => $user->can('changeStatus', $ticket),
                'registerPending' => $user->can('registerPending', $ticket),
                'resolve' => $user->can('resolve', $ticket),
                'reopen' => $user->can('reopen', $ticket),
                'close' => $user->can('close', $ticket),
                // Histórico de auditoria (CHA-14): carregado sob demanda, com autorização própria.
                'viewAudit' => $user->can('viewAudit', $ticket),
            ],
            // Status atual e limite do resumo (CHA-09); só a quem tem `tickets.resolve`.
            'resolutionForm' => $resolves ? [
                'current' => $ticket->status->value,
                'limits' => ['summary' => Ticket::RESOLUTION_SUMMARY_MAX_LENGTH],
            ] : null,
            // Solução registrada (RF-12), para todos que acessam o chamado.
            'resolution' => $this->resolution($ticket),
            // Status atual e limite da justificativa (CHA-10); só ao solicitante original.
            'reopenForm' => $ownsTicket ? [
                'current' => $ticket->status->value,
                'limits' => ['justification' => Ticket::REOPEN_JUSTIFICATION_MAX_LENGTH],
            ] : null,
            // Última reabertura (RF-13), para todos que acessam o chamado.
            'reopening' => $this->reopening($ticket),
            // Status atual (CHA-11); só a quem tem `tickets.close`.
            'closeForm' => $closes ? ['current' => $ticket->status->value] : null,
            // Encerramento registrado (RF-14), para todos que acessam o chamado.
            'closure' => $this->closure($ticket),
            // Tipos de pendência permitidos e seus campos (CHA-08); só a quem pode alterar status.
            'pending' => $managesStatus ? $this->pendingOptions($ticket) : null,
            // Pendência em andamento, somente para a equipe de suporte.
            'activePending' => $user->can('viewPendingDetails', $ticket) ? $this->activePending($ticket) : null,
            // Status atual e destinos permitidos (CHA-07); só a quem pode alterar status.
            'statusChange' => $managesStatus ? [
                'current' => $ticket->status->value,
                'targets' => array_map(fn (TicketStatus $target) => [
                    'value' => $target->value,
                    'label' => $target->label(),
                    'description' => $this->statusChangeDescription($ticket->status, $target),
                ], TicketPolicy::statusChangeTargets($ticket)),
            ] : null,
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

    private function attachmentPayload(TicketAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'type' => $attachment->mime_type,
            'size' => $attachment->size_bytes,
        ];
    }

    /** Limites e tipos dos anexos (RF-23), para a tela avisar antes do envio; o servidor valida de novo. */
    private function attachmentLimits(): array
    {
        return [
            'maxFiles' => config('attachments.max_files_per_upload'),
            'maxFileBytes' => config('attachments.max_file_kb') * 1024,
            'maxUploadBytes' => config('attachments.max_upload_kb') * 1024,
            'extensions' => array_keys(config('attachments.types')),
        ];
    }

    /** Efeito de uma transição da CHA-07, conforme o RF-10 e o RF-15.2. */
    private function statusChangeDescription(TicketStatus $from, TicketStatus $to): ?string
    {
        return match (true) {
            $from === TicketStatus::Open && $to === TicketStatus::InProgress => 'Indica que o responsável atual iniciou o atendimento. O responsável não é alterado.',
            in_array($from, TicketStatus::paused(), true) && $to === TicketStatus::InProgress => 'O atendimento é retomado, a pendência em andamento é encerrada e o SLA de resolução deixa de ficar pausado.',
            default => null,
        };
    }

    /**
     * Opções do registro de pendência (CHA-08): os tipos admitidos no estado atual, com os campos
     * específicos que cada um aceita, e os departamentos ativos quando algum tipo os usa.
     */
    private function pendingOptions(Ticket $ticket): array
    {
        $types = TicketPolicy::pendingTargets($ticket);
        $needsDepartments = collect($types)->contains(fn (TicketStatus $type) => in_array('related_department_id', $type->pendingFields(), true));

        return [
            'current' => $ticket->status->value,
            'types' => array_map(fn (TicketStatus $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $this->pendingDescription($type),
                'fields' => $type->pendingFields(),
            ], $types),
            'departments' => $needsDepartments
                ? Department::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Department $department) => ['value' => (string) $department->id, 'label' => $department->name])
                    ->all()
                : [],
            'limits' => [
                'reason' => TicketSlaPause::REASON_MAX_LENGTH,
                'text' => TicketSlaPause::TEXT_MAX_LENGTH,
            ],
        ];
    }

    /** Quando usar cada tipo de pendência. */
    private function pendingDescription(TicketStatus $type): ?string
    {
        return match ($type) {
            TicketStatus::AwaitingRequester => 'O atendimento depende de uma informação ou ação do solicitante.',
            TicketStatus::Pending => 'O atendimento está temporariamente impedido por outro motivo.',
            TicketStatus::PendingThirdParty => 'O atendimento depende de um fornecedor ou terceiro externo.',
            TicketStatus::PendingDepartments => 'O atendimento depende de outro departamento ou área interna.',
            default => null,
        };
    }

    /** Dados registrados da pendência em andamento, se o chamado estiver pausado. */
    private function activePending(Ticket $ticket): ?array
    {
        if (! in_array($ticket->status, TicketStatus::paused(), true)) {
            return null;
        }

        $pause = $ticket->activeSlaPause()->with(['author:id,name', 'relatedDepartment:id,name'])->first();

        return $pause ? [
            'type' => $pause->pause_status->label(),
            'reason' => $pause->reason,
            'supplier_name' => $pause->supplier_name,
            'protocol_number' => $pause->protocol_number,
            'related_department' => $pause->relatedDepartment?->name,
            'responsible_name' => $pause->responsible_name,
            'expected_return_at' => $pause->expected_return_at?->toDateString(),
            'started_at' => $pause->started_at->toIso8601String(),
            'author' => $pause->author?->name,
        ] : null;
    }

    /**
     * Solução vigente (RF-12), enquanto o chamado está resolvido ou encerrado. O autor vem do evento
     * de resolução: o chamado não guarda uma cópia dele. Resoluções de ciclos anteriores ficam no histórico.
     */
    private function resolution(Ticket $ticket): ?array
    {
        if (! in_array($ticket->status, TicketStatus::finished(), true) || $ticket->resolution_summary === null) {
            return null;
        }

        $event = $ticket->histories()
            ->where('event_type', TicketEvent::Resolved)
            ->with('author:id,name')
            ->latest('id')
            ->first();

        return [
            'summary' => $ticket->resolution_summary,
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'author' => $event?->author?->name,
        ];
    }

    /**
     * Justificativa da última reabertura (RF-13), enquanto o chamado não voltou a ser resolvido ou
     * encerrado. Vem do evento gravado; reaberturas anteriores ficam no histórico.
     */
    private function reopening(Ticket $ticket): ?array
    {
        if (in_array($ticket->status, TicketStatus::finished(), true)) {
            return null;
        }

        $event = $ticket->histories()
            ->where('event_type', TicketEvent::Reopened)
            ->with('author:id,name')
            ->latest('id')
            ->first();

        return $event ? [
            'justification' => $event->new_values['justification'] ?? null,
            'reopened_at' => $event->created_at?->toIso8601String(),
            'author' => $event->author?->name,
        ] : null;
    }

    /**
     * Encerramento (RF-14), enquanto o chamado está encerrado. O instante é o gravado no chamado; o
     * autor vem do evento de encerramento, pois o chamado não guarda uma cópia dele.
     */
    private function closure(Ticket $ticket): ?array
    {
        if ($ticket->status !== TicketStatus::Closed) {
            return null;
        }

        $event = $ticket->histories()
            ->where('event_type', TicketEvent::Closed)
            ->with('author:id,name')
            ->latest('id')
            ->first();

        return [
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'author' => $event?->author?->name,
        ];
    }

    /**
     * Opções dos filtros, sempre a partir de dados reais. Solicitantes e responsáveis vêm
     * apenas dos chamados visíveis, para não revelar usuários fora do escopo.
     */
    private function filterOptions(User $user, bool $restricted): array
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
            'departments' => $catalog(Department::class),
            'categories' => $catalog(TicketCategory::class),
            'assignees' => $people('assigned_to'),
            // Prioridade, SLA e solicitante não são oferecidos na visão restrita (RF-24).
            ...($restricted ? [] : [
                'priorities' => $enum(TicketPriority::cases()),
                'slaStates' => $enum(SlaState::cases()),
                'requesters' => $people('requester_id'),
            ]),
        ];
    }
}
