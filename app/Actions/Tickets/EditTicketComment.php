<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Models\TicketComment;
use App\Models\TicketHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edição do conteúdo de um comentário com preservação da versão anterior (RF-11, regras 5 a 8;
 * CHA-12). O texto substituído, o editor e o instante vão para `ticket_comment_versions`; o evento
 * de auditoria registra que houve a edição. Versão, auditoria e novo texto são gravados juntos ou
 * não são gravados, e a linha do comentário fica bloqueada entre a leitura do texto anterior e a
 * gravação: duas edições simultâneas são serializadas e nenhuma perde a versão que substituiu.
 *
 * Ainda não há rota nem tela de edição. Quem pode editar o quê (comentários próprios, terceiros com
 * `comments.edit_others`) é decidido pelo fluxo de edição, que deve autorizar e validar antes de
 * chamar esta action. A visibilidade não muda aqui: a versão apenas registra a que estava vigente.
 *
 * A auditoria não copia o texto: o conteúdo fica nas versões, sob as regras de leitura do comentário,
 * e não é exposto a quem só tem `audit.view`.
 */
class EditTicketComment
{
    public function __invoke(User $editor, TicketComment $comment, string $body): void
    {
        DB::transaction(function () use ($editor, $comment, $body) {
            $current = TicketComment::whereKey($comment->getKey())->lockForUpdate()->firstOrFail();

            // Sem mudança no conteúdo, não há edição a registrar.
            if ($current->body === $body) {
                return;
            }

            $now = now();

            $version = $current->versions()->forceCreate([
                'edited_by' => $editor->id,
                'previous_body' => $current->body,
                'previous_visibility' => $current->visibility,
                'edited_at' => $now,
                'created_at' => $now,
            ]);

            TicketHistory::query()->forceCreate([
                'ticket_id' => $current->ticket_id,
                'user_id' => $editor->id,
                'event_type' => TicketEvent::CommentEdited,
                'old_values' => null,
                'new_values' => [
                    'comment_id' => $current->id,
                    'version_id' => $version->id,
                    'visibility' => $current->visibility->value,
                ],
                'created_at' => $now,
            ]);

            $current->forceFill(['body' => $body])->save();

            $comment->setRawAttributes($current->getAttributes(), true);
        });
    }
}
