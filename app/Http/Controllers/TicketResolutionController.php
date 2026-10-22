<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\ResolveTicket;
use App\Actions\Tickets\TicketStatusConflict;
use App\Enums\TicketStatus;
use App\Http\Requests\Tickets\ResolveTicketRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TicketResolutionController extends Controller
{
    /**
     * CHA-09 — Resolução (RF-12).
     */
    public function store(ResolveTicketRequest $request, Ticket $ticket, ResolveTicket $resolve): RedirectResponse
    {
        try {
            $resolve(
                $request->user(),
                $ticket,
                TicketStatus::from($request->validated('expected_status')),
                $request->validated('resolution_summary'),
            );
        } catch (TicketStatusConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'O status deste chamado foi alterado por outra pessoa. '
                    .'Os dados foram atualizados; confira antes de tentar novamente.',
            ]);
        }

        return to_route('tickets.show', $ticket)
            ->with('success', 'Chamado resolvido. A solução foi registrada.');
    }
}
