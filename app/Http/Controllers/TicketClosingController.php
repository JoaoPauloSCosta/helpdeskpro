<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\CloseTicket;
use App\Actions\Tickets\TicketStatusConflict;
use App\Enums\TicketStatus;
use App\Http\Requests\Tickets\CloseTicketRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TicketClosingController extends Controller
{
    /**
     * CHA-11 — Encerramento (RF-14).
     */
    public function store(CloseTicketRequest $request, Ticket $ticket, CloseTicket $close): RedirectResponse
    {
        try {
            $close(
                $request->user(),
                $ticket,
                TicketStatus::from($request->validated('expected_status')),
            );
        } catch (TicketStatusConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'O status deste chamado foi alterado por outra pessoa. '
                    .'Os dados foram atualizados; confira o status atual antes de tentar novamente.',
            ]);
        }

        return to_route('tickets.show', $ticket)
            ->with('success', 'Chamado encerrado. O encerramento foi registrado no histórico.');
    }
}
