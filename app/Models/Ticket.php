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
        ];
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
     * Filtra pela situação do SLA. Espelha exatamente a regra de slaState().
     */
    public function scopeWhereSlaState(Builder $query, SlaState $state, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();
        $values = fn (array $statuses) => array_map(fn (TicketStatus $s) => $s->value, $statuses);

        return match ($state) {
            SlaState::Paused => $query->whereIn('tickets.status', $values(TicketStatus::paused())),
            SlaState::OnTrack => $query->whereIn('tickets.status', $values(TicketStatus::running()))
                ->where('tickets.resolution_due_at', '>=', $now),
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
     * Situação do SLA de resolução a partir dos prazos gravados pelo backend.
     * Não estima tempo restante nem calcula prazos: apenas compara os instantes registrados.
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

        return $now->gt($due) ? SlaState::Overdue : SlaState::OnTrack;
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

        return $now->gt($due) ? SlaState::Overdue : SlaState::OnTrack;
    }
}
