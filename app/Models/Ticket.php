<?php

namespace App\Models;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Carbon\CarbonInterface;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Chamado. Colunas de SLA (RF-15 a RF-17, RN-15): `sla_policy_id` e `sla_*_minutes` guardam a política
 * e as metas aplicadas (nulas sem política, RF-15.3); `*_due_at` são os prazos vigentes;
 * `*_breached_at` guardam a primeira violação de cada indicador e nunca são apagadas;
 * `resolution_effective_seconds` é o tempo de resolução sem as pausas, gravado ao resolver.
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * Somente o que o solicitante informa. Número, autor, departamento, status e prazos
     * são definidos pelo backend (RF-05).
     */
    protected $fillable = ['title', 'description', 'category_id', 'priority'];

    /** `title` é VARCHAR(255). */
    public const TITLE_MAX_LENGTH = 255;

    /** `description` é TEXT (65.535 bytes): 16.383 caracteres cabem mesmo com 4 bytes cada (utf8mb4). */
    public const DESCRIPTION_MAX_LENGTH = 16383;

    /** `resolution_summary` também é TEXT: mesmo limite da descrição. */
    public const RESOLUTION_SUMMARY_MAX_LENGTH = 16383;

    /**
     * Justificativa da reabertura (CHA-10), gravada no evento de auditoria. A coluna JSON não impõe
     * um limite menor; vale o mesmo dos demais textos livres do chamado (motivo, resumo, comentário).
     */
    public const REOPEN_JUSTIFICATION_MAX_LENGTH = 16383;

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'first_response_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'sla_policy_id' => 'integer',
            'sla_first_response_minutes' => 'integer',
            'sla_resolution_minutes' => 'integer',
            'first_response_breached_at' => 'datetime',
            'resolution_breached_at' => 'datetime',
            'resolution_effective_seconds' => 'integer',
        ];
    }

    /** Política de SLA aplicada na abertura ou na última troca de prioridade; nula sem política (RF-15.3). */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TicketHistory::class);
    }

    /** Pendências e pausas do SLA de resolução (RF-17), uma por ciclo de pausa. */
    public function slaPauses(): HasMany
    {
        return $this->hasMany(TicketSlaPause::class);
    }

    /** A pausa em andamento, se houver. O banco garante no máximo uma por chamado. */
    public function activeSlaPause(): HasOne
    {
        return $this->hasOne(TicketSlaPause::class)->whereNull('ended_at');
    }

    /**
     * Assinatura dos atributos editáveis na CHA-04. Detecta se outra pessoa alterou prioridade,
     * categoria ou departamento enquanto o formulário estava aberto, sem depender de
     * `updated_at` (que também muda a cada comentário).
     */
    public function editVersion(): string
    {
        return sha1(implode('|', [$this->priority->value, $this->category_id, $this->department_id]));
    }

    /** A URL pública usa o identificador `HD-XXXXXXXX`, nunca a chave primária (RF-06). */
    public function getRouteKeyName(): string
    {
        return 'ticket_number';
    }

    /**
     * Escopo de visibilidade (RF-07). Deve ser aplicado antes de pesquisa, filtros,
     * ordenação, contagem e paginação.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('tickets.view_all_departments')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->where('tickets.requester_id', $user->id)
                ->orWhere('tickets.assigned_to', $user->id);

            // Chamados elegíveis para autoatribuição (RF-09): abertos e sem responsável.
            if ($user->hasPermission('tickets.self_assign')) {
                $query->orWhere(fn (Builder $query) => $query
                    ->where('tickets.status', TicketStatus::Open)
                    ->whereNull('tickets.assigned_to'));
            }
        });
    }

    /**
     * Filtra pela situação do SLA. Espelha exatamente a regra de slaState(): a situação atual, pelo
     * prazo vigente. Não considera `resolution_breached_at`, que é o histórico de violação (RF-16.3).
     */
    public function scopeWhereSlaState(Builder $query, SlaState $state, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();
        $values = fn (array $statuses) => array_map(fn (TicketStatus $s) => $s->value, $statuses);

        return match ($state) {
            SlaState::Paused => $query->whereIn('tickets.status', $values(TicketStatus::paused())),
            SlaState::OnTrack => $query->whereIn('tickets.status', $values(TicketStatus::running()))
                ->where('tickets.resolution_due_at', '>=', $now)
                ->where(fn (Builder $query) => $query
                    ->whereNull('tickets.sla_resolution_minutes')
                    ->orWhereRaw('NOT ('.self::atRiskSql($query, $now)[0].')', self::atRiskSql($query, $now)[1])),
            SlaState::AtRisk => $query->whereIn('tickets.status', $values(TicketStatus::running()))
                ->where('tickets.resolution_due_at', '>=', $now)
                ->whereNotNull('tickets.sla_resolution_minutes')
                ->whereRaw(...self::atRiskSql($query, $now)),
            SlaState::Met => $query->whereIn('tickets.status', $values(TicketStatus::finished()))
                ->whereNotNull('tickets.resolution_due_at')
                ->whereNotNull('tickets.resolved_at')
                ->whereColumn('tickets.resolved_at', '<=', 'tickets.resolution_due_at'),
            SlaState::Overdue => $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereIn('tickets.status', $values(TicketStatus::running()))
                    ->where('tickets.resolution_due_at', '<', $now))
                ->orWhere(fn (Builder $query) => $query
                    ->whereIn('tickets.status', $values(TicketStatus::finished()))
                    ->whereNotNull('tickets.resolution_due_at')
                    ->whereNotNull('tickets.resolved_at')
                    ->whereColumn('tickets.resolved_at', '>', 'tickets.resolution_due_at'))),
            SlaState::None => $query->whereNotIn('tickets.status', $values(TicketStatus::paused()))
                ->where(fn (Builder $query) => $query
                    ->whereNull('tickets.resolution_due_at')
                    ->orWhere(fn (Builder $query) => $query
                        ->whereIn('tickets.status', $values(TicketStatus::finished()))
                        ->whereNull('tickets.resolved_at'))),
        };
    }

    /**
     * Condição SQL de "Em risco": falta no máximo (100 − AT_RISK_PERCENT)% da meta aplicada, ou seja,
     * `prazo − meta × 20% <= agora`. Mesma regra de atRisk(), em segundos inteiros. A subtração de
     * segundos muda com o driver (MariaDB em produção, SQLite nos testes).
     *
     * @return array{0: string, 1: list<string>} SQL e bindings
     */
    private static function atRiskSql(Builder $query, CarbonInterface $now): array
    {
        $secondsPerMinute = 60 * (100 - SlaState::AT_RISK_PERCENT) / 100;
        $now = $now->format('Y-m-d H:i:s');

        return $query->getConnection()->getDriverName() === 'sqlite'
            ? ["(strftime('%s', tickets.resolution_due_at) - tickets.sla_resolution_minutes * {$secondsPerMinute}) <= strftime('%s', ?)", [$now]]
            : ["DATE_SUB(tickets.resolution_due_at, INTERVAL tickets.sla_resolution_minutes * {$secondsPerMinute} SECOND) <= ?", [$now]];
    }

    /**
     * "Em risco" (D7): ainda no prazo, com `AT_RISK_PERCENT`% ou mais da meta consumida. Sem meta
     * aplicada não há como medir o consumo, e o chamado segue "No prazo".
     */
    private static function atRisk(?int $targetMinutes, CarbonInterface $due, CarbonInterface $now): bool
    {
        if ($targetMinutes === null) {
            return false;
        }

        $target = $targetMinutes * 60;
        $remaining = $due->getTimestamp() - $now->getTimestamp();

        return ($target - $remaining) * 100 >= $target * SlaState::AT_RISK_PERCENT;
    }

    /**
     * Situação atual do SLA de resolução a partir dos prazos gravados pelo backend.
     * Não estima tempo restante nem calcula prazos: apenas compara os instantes registrados.
     *
     * É o estado de conformidade com a meta vigente (RF-16.3). A violação histórica fica à parte, em
     * `resolution_breached_at` e `first_response_breached_at`: um chamado pode estar "No prazo" depois
     * de trocar para uma meta mais longa e ainda assim ter uma violação registrada.
     */
    public function slaState(?CarbonInterface $now = null): SlaState
    {
        $now ??= now();
        $due = $this->resolution_due_at;

        if (in_array($this->status, TicketStatus::paused(), true)) {
            return SlaState::Paused;
        }

        if ($due === null) {
            return SlaState::None;
        }

        if (in_array($this->status, TicketStatus::finished(), true)) {
            if ($this->resolved_at === null) {
                return SlaState::None;
            }

            return $this->resolved_at->lte($due) ? SlaState::Met : SlaState::Overdue;
        }

        if ($now->gt($due)) {
            return SlaState::Overdue;
        }

        return self::atRisk($this->sla_resolution_minutes, $due, $now) ? SlaState::AtRisk : SlaState::OnTrack;
    }

    /**
     * Situação do SLA de primeira resposta (RF-15.1). Independe das pausas, que afetam apenas
     * a resolução. Como slaState(), só compara os instantes gravados pelo backend.
     */
    public function firstResponseSlaState(?CarbonInterface $now = null): SlaState
    {
        $now ??= now();
        $due = $this->first_response_due_at;

        if ($due === null) {
            return SlaState::None;
        }

        if ($this->first_response_at !== null) {
            return $this->first_response_at->lte($due) ? SlaState::Met : SlaState::Overdue;
        }

        if ($now->gt($due)) {
            return SlaState::Overdue;
        }

        return self::atRisk($this->sla_first_response_minutes, $due, $now) ? SlaState::AtRisk : SlaState::OnTrack;
    }
}
