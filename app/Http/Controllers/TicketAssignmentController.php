<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\TicketAssignmentConflict;
use App\Enums\TicketEvent;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TicketAssignmentController extends Controller
{
    /**
     * CHA-05 — Atribuição e reatribuição de técnico (RF-08).
     */
    public function update(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assignTicket): RedirectResponse
    {
        try {
            $event = $assignTicket(
                $request->user(),
                $ticket,
                (int) $request->validated('technician_id'),
                $request->validated('expected_assignee_id') === null ? null : (int) $request->validated('expected_assignee_id'),
            );
        } catch (TicketAssignmentConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'O responsável por este chamado foi alterado por outra pessoa. '
                    .'Os dados foram atualizados; confira e tente novamente.',
            ]);
        }

        $name = $ticket->assignee()->value('name');

        return to_route('tickets.show', $ticket)->with('success', $event === TicketEvent::Assigned
            ? "Chamado atribuído a {$name}."
            : "Chamado reatribuído a {$name}.");
    }
}
