<?php

namespace App\Models;

use App\Enums\CommentVisibility;
use Database\Factories\TicketCommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketComment extends Model
{
    /** @use HasFactory<TicketCommentFactory> */
    use HasFactory;

    /** Somente o conteúdo. Chamado, autor e visibilidade são definidos pelo backend (RF-11). */
    protected $fillable = ['body'];

    /** Um novo comentário atualiza a "última atualização" do chamado. */
    protected $touches = ['ticket'];

    /** `body` é TEXT (65.535 bytes): 16.383 caracteres cabem mesmo com 4 bytes cada (utf8mb4). */
    public const BODY_MAX_LENGTH = 16383;

    protected function casts(): array
    {
        return ['visibility' => CommentVisibility::class];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Conteúdos substituídos por edições (CHA-12), na ordem em que foram gravados. */
    public function versions(): HasMany
    {
        return $this->hasMany(TicketCommentVersion::class);
    }

    /**
     * Mensagens que o usuário pode ler (RF-11). Sem `tickets.view_internal_notes`,
     * as notas internas são excluídas já na consulta.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('tickets.view_internal_notes')) {
            return $query;
        }

        return $query->where('visibility', CommentVisibility::Public);
    }
}
