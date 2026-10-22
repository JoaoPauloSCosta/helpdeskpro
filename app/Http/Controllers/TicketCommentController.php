<?php

namespace App\Http\Controllers;

use App\Enums\TicketEvent;
use App\Http\Requests\Tickets\StoreTicketCommentRequest;
use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class TicketCommentController extends Controller
{
    /**
     * Registra um comentário público ou uma nota interna (RF-11). A mensagem e o evento de
     * auditoria (RF-19) são gravados juntos ou não são gravados. Como na edição (CHA-12), a auditoria
     * não copia o texto: o conteúdo fica na mensagem, sob as regras de leitura do comentário.
     *
     * Pendência de outra etapa: o registro da primeira resposta pública da equipe de suporte para
     * o SLA (Etapa 7) ainda não existe.
     */
    public function store(StoreTicketCommentRequest $request, Ticket $ticket): RedirectResponse
    {
        $comment = (new TicketComment)->fill($request->safe()->only('body'))->forceFill([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'visibility' => $request->visibility(),
        ]);

        DB::transaction(function () use ($ticket, $comment) {
            $comment->save();

            $ticket->histories()->forceCreate([
                'user_id' => $comment->user_id,
                'event_type' => TicketEvent::CommentAdded,
                'old_values' => null,
                'new_values' => [
                    'comment_id' => $comment->id,
                    'visibility' => $comment->visibility->value,
                ],
                'created_at' => $comment->created_at,
            ]);
        });

        return to_route('tickets.show', $ticket);
    }
}
