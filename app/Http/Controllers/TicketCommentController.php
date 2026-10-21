<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tickets\StoreTicketCommentRequest;
use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Http\RedirectResponse;

class TicketCommentController extends Controller
{
    /**
     * Registra um comentário público ou uma nota interna (RF-11).
     *
     * Pendências de outras etapas: o evento de auditoria (Etapa 6) e o registro da primeira
     * resposta pública da equipe de suporte para o SLA (Etapa 7) ainda não existem.
     */
    public function store(StoreTicketCommentRequest $request, Ticket $ticket): RedirectResponse
    {
        $comment = (new TicketComment)->fill($request->safe()->only('body'))->forceFill([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'visibility' => $request->visibility(),
        ]);
        $comment->save();

        return to_route('tickets.show', $ticket);
    }
}
