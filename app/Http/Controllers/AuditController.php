<?php

namespace App\Http\Controllers;

use App\Enums\AdminEvent;
use App\Enums\TicketPriority;
use App\Http\Requests\Audit\AuditIndexRequest;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\TicketCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    /** Mesmo tamanho de página das demais listagens administrativas. */
    private const PER_PAGE = 15;

    /** Limite de caracteres de um valor de texto exibido nos detalhes. */
    private const VALUE_LIMIT = 500;

    /**
     * Entidades com eventos em `audit_logs`: filtro da tela => [model, rótulo]. A classe gravada em
     * `auditable_type` nunca é exposta.
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    public const ENTITIES = [
        'user' => [User::class, 'Usuário'],
        'department' => [Department::class, 'Departamento'],
        'category' => [TicketCategory::class, 'Categoria'],
        'role' => [Role::class, 'Papel'],
        'sla_policy' => [SlaPolicy::class, 'Política de SLA'],
    ];

    /**
     * Campos do registro que a tela apresenta, com o rótulo e o formato. Lista fechada: o JSON gravado
     * nunca é repassado como está, então qualquer chave fora dela (inclusive uma que um dia contenha
     * credenciais por engano) não chega ao navegador.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const FIELDS = [
        'name' => ['Nome', 'text'],
        'email' => ['E-mail', 'text'],
        'slug' => ['Identificador', 'text'],
        'description' => ['Descrição', 'text'],
        'role' => ['Papel', 'named'],
        'department' => ['Departamento', 'named'],
        'priority' => ['Prioridade', 'priority'],
        'first_response_minutes' => ['Meta de primeira resposta', 'minutes'],
        'resolution_minutes' => ['Meta de resolução', 'minutes'],
        'is_active' => ['Situação', 'active'],
        'is_system' => ['Papel do sistema', 'yesno'],
        'added' => ['Permissões concedidas', 'list'],
        'removed' => ['Permissões retiradas', 'list'],
    ];

    /**
     * AUD-01 — Consulta de auditoria (RF-19), somente leitura. A fonte é `audit_logs`, a trilha das
     * alterações administrativas; os eventos de cada chamado ficam em `ticket_histories` e seguem
     * consultáveis no próprio chamado (CHA-14). Exige `audit.view`, filtros e paginação no servidor.
     */
    public function index(AuditIndexRequest $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $request->filters();
        $direction = $request->direction();

        $logs = AuditLog::query()
            ->select(['id', 'user_id', 'event_type', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'created_at'])
            ->with($this->relations())
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->orderBy('created_at', $direction)
            // Critério de desempate estável para a paginação.
            ->orderBy('id', $direction)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLog $log) => $this->row($log));

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'sort' => ['key' => 'created_at', 'direction' => $direction],
            'options' => [
                'events' => collect(AdminEvent::cases())
                    ->map(fn (AdminEvent $event) => ['value' => $event->value, 'label' => $event->label()])
                    ->all(),
                'entities' => collect(self::ENTITIES)
                    ->map(fn (array $entity, string $key) => ['value' => $key, 'label' => $entity[1]])
                    ->values()
                    ->all(),
            ],
            'rangeLimit' => AuditIndexRequest::MAX_RANGE_DAYS,
            // Há eventos gravados, independentemente dos filtros: separa "base vazia" de "sem resultados".
            'hasAnyLogs' => AuditLog::query()->exists(),
            'detail' => $this->detail($request->selected()),
            'selected' => $request->selected(),
        ]);
    }

    /**
     * Relações lidas junto dos eventos (sem N+1). A entidade vem só com os campos que a identificam:
     * senha, tokens e demais atributos nunca saem do banco.
     *
     * @return array<string, mixed>
     */
    private function relations(): array
    {
        return [
            'author:id,name',
            'auditable' => fn (MorphTo $morph) => $morph->constrain([
                User::class => fn (Builder $query) => $query->select(['id', 'name']),
                Department::class => fn (Builder $query) => $query->select(['id', 'name']),
                TicketCategory::class => fn (Builder $query) => $query->select(['id', 'name']),
                Role::class => fn (Builder $query) => $query->select(['id', 'name']),
                SlaPolicy::class => fn (Builder $query) => $query->select(['id', 'priority']),
            ]),
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['q'])) {
            // ESCAPE explícito, porque o SQLite (testes) não tem escape padrão como o MariaDB.
            $like = '%'.strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

            $query->whereHas('author', fn (Builder $author) => $author
                ->whereRaw("users.name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("users.email LIKE ? ESCAPE '!'", [$like]));
        }

        if (isset($filters['event'])) {
            $query->where('event_type', $filters['event']);
        }

        if (isset($filters['entity'])) {
            $query->where('auditable_type', self::ENTITIES[$filters['entity']][0]);
        }

        if (isset($filters['entity_id'])) {
            $query->where('auditable_id', (int) $filters['entity_id']);
        }

        // Dias inteiros em UTC, inclusive nas duas pontas.
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $filters['from'], 'UTC')->startOfDay());
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('Y-m-d', $filters['to'], 'UTC')->addDay()->startOfDay());
        }
    }

    /** Uma linha da listagem. */
    private function row(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'event' => ['value' => $log->event_type->value, 'label' => $log->event_type->label()],
            'author' => $log->author?->name,
            'entity' => $this->entity($log),
        ];
    }

    /**
     * Entidade afetada. O nome vem do registro do evento quando gravado (o nome da época) e, na falta
     * dele, do cadastro atual; o identificador é sempre o gravado, mesmo que o cadastro já não exista.
     */
    private function entity(AuditLog $log): array
    {
        $type = collect(self::ENTITIES)->first(fn (array $entity) => $entity[0] === $log->auditable_type);
        $live = $log->auditable;

        $name = $log->new_values['name'] ?? $log->old_values['name'] ?? $live?->name ?? null;

        if ($name === null && $live instanceof SlaPolicy) {
            $name = "Prioridade {$live->priority->label()}";
        }

        return [
            'type' => $type[1] ?? 'Registro',
            'id' => $log->auditable_id,
            'name' => is_string($name) ? Str::limit($name, 120) : null,
            // O cadastro pode ter sido alterado depois do evento; o histórico continua consultável.
            'exists' => $live !== null,
        ];
    }

    /**
     * Evento aberto no painel. Nulo quando não há seleção ou o identificador não existe (a tela informa
     * que o evento não foi encontrado). O acesso à tela já foi autorizado acima.
     */
    private function detail(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $log = AuditLog::query()
            ->with($this->relations())
            ->find($id, ['id', 'user_id', 'event_type', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'created_at']);

        if (! $log) {
            return null;
        }

        $old = is_array($log->old_values) ? $log->old_values : [];
        $new = is_array($log->new_values) ? $log->new_values : [];

        $changes = collect(self::FIELDS)
            ->filter(fn ($definition, string $field) => array_key_exists($field, $new) || array_key_exists($field, $old))
            ->map(fn (array $definition, string $field) => [
                'field' => $definition[0],
                'from' => array_key_exists($field, $old) ? $this->value($old[$field], $definition[1]) : null,
                'to' => array_key_exists($field, $new) ? $this->value($new[$field], $definition[1]) : null,
            ])
            // Sem valor algum (ex.: lista vazia na criação do papel), não há o que mostrar.
            ->reject(fn (array $change) => in_array($change['from'], [null, []], true) && in_array($change['to'], [null, []], true))
            ->values()
            ->all();

        return [...$this->row($log), 'changes' => $changes];
    }

    /**
     * Valor no formato da tela: texto simples (a interface o escapa) ou lista de textos. Valores de
     * tipo inesperado viram nulo em vez de serem repassados.
     *
     * @return string|list<string>|null
     */
    private function value(mixed $value, string $format): string|array|null
    {
        if ($value === null) {
            return null;
        }

        return match ($format) {
            'text' => is_string($value) || is_numeric($value) ? Str::limit((string) $value, self::VALUE_LIMIT) : null,
            'named' => is_array($value) && is_string($value['label'] ?? null) ? Str::limit($value['label'], self::VALUE_LIMIT) : null,
            'priority' => TicketPriority::tryFrom((string) $value)?->label(),
            'minutes' => is_int($value) ? $this->minutes($value) : null,
            'active' => is_bool($value) ? ($value ? 'Ativo' : 'Inativo') : null,
            'yesno' => is_bool($value) ? ($value ? 'Sim' : 'Não') : null,
            'list' => is_array($value)
                ? collect($value)->filter(fn ($item) => is_string($item))->map(fn (string $item) => Str::limit($item, 100))->values()->all()
                : null,
            default => null,
        };
    }

    /** Meta em minutos, por extenso (ex.: "1 h 30 min"). */
    private function minutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest} min",
            $rest === 0 => "{$hours} h",
            default => "{$hours} h {$rest} min",
        };
    }
}
