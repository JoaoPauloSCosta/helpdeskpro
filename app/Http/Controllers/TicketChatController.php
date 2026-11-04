<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Conversa do atendimento via chat que gerou o chamado (RF-34, contrato da Etapa 1, seção 6). Quem pode ver o
 * chamado pode ler a conversa: ela é pública entre solicitante e técnico, sem notas internas. Somente leitura,
 * sob demanda, para não pesar a abertura do chamado.
 */
class TicketChatController extends Controller
{
    public function show(Ticket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket);

        $session = $ticket->chatSession;
        abort_unless($session, 404);

        return response()->json([
            'messages' => $session->messages()->with('author:id,name')->orderBy('id')->get()
                ->map(fn (ChatMessage $message) => $message->toPayload())->all(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
