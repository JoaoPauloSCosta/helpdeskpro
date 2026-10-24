<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo anexado à abertura de um chamado ou a uma mensagem (RF-23). O caminho de armazenamento é
 * gerado pelo sistema e nunca sai do servidor; o nome original serve só para exibição.
 */
class TicketAttachment extends Model
{
    /** Tudo é definido pelo backend (RF-23): nada vem do formulário. */
    protected $guarded = ['*'];

    protected $hidden = ['storage_path', 'sha256'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** Mensagem a que pertence; nulo para anexos da abertura (públicos). */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'ticket_comment_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
