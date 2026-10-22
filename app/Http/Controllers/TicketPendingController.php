<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\RegisterTicketPending;
use App\Actions\Tickets\TicketStatusConflict;
use App\Enums\TicketStatus;
use App\Http\Requests\Tickets\RegisterTicketPendingRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TicketPendingController extends Controller
{
    /**
     * CHA-08 — Registro de pendência (RF-17).
     */
    public function store(RegisterTicketPendingRequest $request, Ticket $ticket, RegisterTicketPending $registerPending): RedirectResponse
    {
        try {
            $registerPending(
                $request->user(),
                $ticket,
                TicketStatus::from($request->validated('expected_status')),
                $request->type(),
                $request->validated('reason'),
                $request->details(),
            );
        } catch (TicketStatusConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'O status deste chamado foi alterado por outra pessoa. '
                    .'Os dados foram atualizados; confira e tente novamente.',
            ]);
        }

        return to_route('tickets.show', $ticket)
            ->with('success', "Pendência registrada. Status alterado para {$ticket->status->label()}.");
    }
}
