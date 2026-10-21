<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\TicketStatusConflict;
use App\Enums\TicketStatus;
use App\Http\Requests\Tickets\ChangeTicketStatusRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TicketStatusController extends Controller
{
    /**
     * CHA-07 — Alteração de status (RF-10).
     */
    public function update(ChangeTicketStatusRequest $request, Ticket $ticket, ChangeTicketStatus $changeStatus): RedirectResponse
    {
        try {
            $changeStatus(
                $request->user(),
                $ticket,
                TicketStatus::from($request->validated('expected_status')),
                TicketStatus::from($request->validated('status')),
            );
        } catch (TicketStatusConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'O status deste chamado foi alterado por outra pessoa. '
                    .'Os dados foram atualizados; confira e tente novamente.',
            ]);
        }

        return to_route('tickets.show', $ticket)->with('success', "Status alterado para {$ticket->status->label()}.");
    }
}
