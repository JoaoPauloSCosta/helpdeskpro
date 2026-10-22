<?php

namespace App\Models;

use App\Enums\CommentVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conteúdo de um comentário antes de uma edição (RF-11). Imutável: só tem `created_at`, e o editor
 * e o instante vêm sempre do backend. A versão guarda o texto substituído; quem escreveu esse texto
 * é o autor do comentário (primeira versão) ou o editor da versão anterior.
 */
class TicketCommentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'previous_visibility' => CommentVisibility::class,
            'edited_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'ticket_comment_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * Versões que o usuário pode ler: a mesma regra de TicketComment::scopeVisibleTo(), aplicada à
     * visibilidade que cada versão tinha. Sem `tickets.view_internal_notes`, versões internas são
     * excluídas já na consulta.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('tickets.view_internal_notes')) {
            return $query;
        }

        return $query->where('previous_visibility', CommentVisibility::Public);
    }
}
