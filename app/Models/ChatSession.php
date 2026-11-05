<?php

namespace App\Models;

use App\Enums\ChatOutcome;
use App\Enums\ChatSessionStatus;
use Database\Factories\ChatSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sessão de chat (RF-26 a RF-36). Solicitante, departamento, técnico, estado e horários são definidos pelo
 * backend, nunca pelos dados do navegador. A fila são as sessões `Waiting` em ordem de `id`.
 */
class ChatSession extends Model
{
    /** @use HasFactory<ChatSessionFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ChatSessionStatus::class,
            'outcome' => ChatOutcome::class,
            'accepted_at' => 'datetime',
            'first_response_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Posição na fila (RF-27), calculada no servidor a cada consulta e nunca gravada: 1 é o próximo a ser
     * atendido. Nula fora da fila.
     */
    public function queuePosition(): ?int
    {
        if ($this->status !== ChatSessionStatus::Waiting) {
            return null;
        }

        return static::where('status', ChatSessionStatus::Waiting)->where('id', '<=', $this->id)->count();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** Técnico responsável atual; nulo enquanto aguarda na fila. */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Chamado gerado no encerramento (RF-34); no máximo um por sessão. */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChatSessionEvent::class);
    }
}
