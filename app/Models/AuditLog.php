<?php

namespace App\Models;

use App\Enums\AdminEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Evento de auditoria administrativa (RF-19). Imutável: só tem `created_at`, e o autor e o
 * instante vêm sempre do backend. Nunca guarda senhas, hashes ou tokens.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'event_type' => AdminEvent::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
