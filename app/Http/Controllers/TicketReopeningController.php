<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\ReopenTicket;
use App\Actions\Tickets\TicketStatusConflict;
use App\Enums\TicketStatus;
use App\Http\Requests\Tickets\ReopenTicketRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TicketReopeningController extends Controller
{
    /**
     * CHA-10 — Reabertura (RF-13).
     */
    public function store(ReopenTicketRequest $request, Ticket $ticket, ReopenTicket $reopen): RedirectResponse
    {
        try {
            $reopen(
                $request->user(),
                $ticket,
                TicketStatus::from($request->validated('expected_status')),
                $request->validated('justification'),
            );
        } catch (TicketStatusConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'O status deste chamado foi alterado por outra pessoa. '
                    .'Os dados foram atualizados; confira o status atual antes de tentar novamente.',
            ]);
        }

        return to_route('tickets.show', $ticket)
            ->with('success', 'Chamado reaberto. A justificativa foi registrada e o status voltou para Aberto.');
    }
}
