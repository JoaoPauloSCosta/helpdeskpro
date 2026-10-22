<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pendência registrada (RF-17) e período de pausa do SLA de resolução (RF-15.2). Autor, início,
 * fim e duração vêm sempre do backend; depois de encerrada, a pausa não é mais alterada.
 */
class TicketSlaPause extends Model
{
    protected $guarded = ['*'];

    /** `reason` é TEXT (65.535 bytes): 16.383 caracteres cabem mesmo com 4 bytes cada (utf8mb4). */
    public const REASON_MAX_LENGTH = 16383;

    /** Campos de texto específicos são VARCHAR(255). */
    public const TEXT_MAX_LENGTH = 255;

    protected function casts(): array
    {
        return [
            'pause_status' => TicketStatus::class,
            'expected_return_at' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function relatedDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'related_department_id');
    }

    /** Pausa em andamento: ainda sem fim registrado. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Encerra a pausa na retomada (RF-17, regra 2). A duração é calculada aqui, a partir dos
     * instantes gravados, e nunca fica negativa.
     */
    public function close(CarbonInterface $at): void
    {
        $this->forceFill([
            'ended_at' => $at,
            'duration_seconds' => max(0, (int) $this->started_at->diffInSeconds($at)),
        ])->save();
    }
}
