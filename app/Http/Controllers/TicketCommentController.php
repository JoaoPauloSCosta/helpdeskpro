<?php

namespace App\Http\Controllers;

use App\Enums\CommentVisibility;
use App\Enums\TicketEvent;
use App\Http\Requests\Tickets\StoreTicketCommentRequest;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Support\SlaClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class TicketCommentController extends Controller
{
    /**
     * Registra um comentário público ou uma nota interna (RF-11). A mensagem e o evento de
     * auditoria (RF-19) são gravados juntos ou não são gravados. Como na edição (CHA-12), a auditoria
     * não copia o texto: o conteúdo fica na mensagem, sob as regras de leitura do comentário.
     *
     * Primeira resposta (RF-15.1, RN-11, D1): na mesma transação, com o chamado bloqueado, o primeiro
     * comentário público de quem tem `tickets.view_internal_notes` e não é o solicitante grava
     * `first_response_at`. Nota interna, comentário do solicitante, edição e retomada não contam, e
     * depois de gravada ela não muda. Respondido (ou vencido) fora do prazo, a violação é registrada
     * aqui mesmo (RN-15), junto com a de resolução, se for o caso.
     */
    public function store(StoreTicketCommentRequest $request, Ticket $ticket): RedirectResponse
    {
        $comment = (new TicketComment)->fill($request->safe()->only('body'))->forceFill([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'visibility' => $request->visibility(),
        ]);

        DB::transaction(function () use ($ticket, $comment, $request) {
            $comment->save();
            $this->registerSla($request->user(), $ticket, $comment);

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

    /** Primeira resposta da equipe e violações de SLA apuradas neste instante. */
    private function registerSla(User $author, Ticket $ticket, TicketComment $comment): void
    {
        $current = Ticket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

        if ($current->first_response_at === null
            && $comment->visibility === CommentVisibility::Public
            && $author->hasPermission('tickets.view_internal_notes')
            && $author->id !== $current->requester_id) {
            $current->forceFill(['first_response_at' => $comment->created_at])->save();
        }

        SlaClock::recordBreaches($current, $comment->created_at);
    }
}
