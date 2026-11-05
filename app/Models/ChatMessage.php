<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem do chat (RF-30). Texto puro; não é editada nem apagada. */
class ChatMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * Formato único da mensagem, na resposta HTTP e no aviso em tempo real. O texto é puro: a tela o exibe por
     * interpolação, nunca como HTML.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $this->loadMissing('author:id,name');

        return [
            'id' => $this->id,
            'session_id' => $this->chat_session_id,
            'author' => ['id' => $this->user_id, 'name' => $this->author->name],
            'body' => $this->body,
            'client_id' => $this->client_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
