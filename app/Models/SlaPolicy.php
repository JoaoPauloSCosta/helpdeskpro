<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Models\Concerns\HasAuditLogs;
use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Política de SLA (RF-15.3): metas de primeira resposta e de resolução, em minutos corridos,
 * para uma prioridade. Só pode haver uma ativa por prioridade (índice único em `active_priority`).
 * O motor de SLA (App\Support\SlaClock) aplica estas metas na abertura do chamado e na troca de prioridade.
 */
class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasAuditLogs, HasFactory;

    /** Meta mínima: um prazo zero venceria no instante da abertura. */
    public const TARGET_MIN_MINUTES = 1;

    /**
     * Meta máxima (365 dias). O PRD não define um teto; este é só um limite de sanidade para
     * barrar erros de digitação, bem abaixo do limite da coluna.
     */
    public const TARGET_MAX_MINUTES = 525_600;

    protected $fillable = ['priority', 'first_response_minutes', 'resolution_minutes', 'is_active'];

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Assinatura dos campos da SLA-03. Detecta se outra pessoa alterou a política enquanto o
     * formulário estava aberto, sem depender de `updated_at` (precisão de segundos).
     */
    public function editVersion(): string
    {
        return sha1(implode('|', [$this->priority->value, $this->first_response_minutes, $this->resolution_minutes, (int) $this->is_active]));
    }

    /** Se a prioridade já tem uma política ativa (a que seria aplicada), além de `$except`. */
    public static function hasActiveFor(TicketPriority $priority, ?int $except = null): bool
    {
        return static::where('priority', $priority->value)
            ->where('is_active', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->exists();
    }
}
