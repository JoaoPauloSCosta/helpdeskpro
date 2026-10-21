<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\SelfAssignTicket;
use App\Actions\Tickets\TicketNotSelfAssignable;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TicketSelfAssignmentController extends Controller
{
    /**
     * CHA-06 — Autoatribuição (RF-09). O técnico é sempre o usuário autenticado; nenhum dado
     * do corpo da requisição é usado.
     */
    public function store(Request $request, Ticket $ticket, SelfAssignTicket $selfAssign): RedirectResponse
    {
        $user = $request->user();

        // Permissão conferida antes de qualquer leitura do estado do chamado.
        abort_unless($user->isEligibleAssignee(), 403);

        try {
            $selfAssign($user, $ticket);
        } catch (TicketNotSelfAssignable) {
            // Mensagem neutra: não informa quem assumiu nem o novo estado do chamado.
            $error = ['self_assign' => 'Este chamado não está mais disponível para autoatribuição. '
                .'Outro técnico pode tê-lo assumido; os dados foram atualizados.'];

            // Voltar para os detalhes só se o usuário ainda puder vê-los; senão, para a listagem.
            $fromDetails = rtrim(parse_url(url()->previous(), PHP_URL_PATH) ?? '', '/') === parse_url(route('tickets.show', $ticket), PHP_URL_PATH);
            $ticket->refresh();

            return ($fromDetails && ! $user->can('view', $ticket) ? to_route('tickets.index') : back())
                ->withErrors($error);
        }

        return to_route('tickets.show', $ticket)->with('success', 'Chamado assumido com sucesso.');
    }
}
