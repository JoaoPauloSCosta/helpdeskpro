<?php

namespace App\Http\Controllers;

use App\Actions\Chat\RequestChatSession;
use App\Http\Requests\Chat\EndChatSessionRequest;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Support\ChatConsoleState;
use App\Support\ChatSessionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Telas do chat: CHAT-01 a CHAT-03 (solicitante) e CHAT-04/05 (técnico). A tela só reflete o que o servidor
 * manda; as operações são as dos endpoints JSON, cada uma autorizada de novo no backend.
 */
class ChatPageController extends Controller
{
    /** Solicitante: a sessão aberta (aguardando ou em atendimento), se houver. */
    public function index(Request $request): Response
    {
        Gate::authorize('requestChat');

        $open = RequestChatSession::openSessionOf($request->user());

        return Inertia::render('Chat/Index', [
            'session' => $open ? ChatSessionState::for($open, $request->user()) : null,
        ]);
    }

    /** Técnico ou gestor: disponibilidade, fila e atendimentos ativos, conforme as permissões. */
    public function console(Request $request): Response
    {
        abort_unless(
            Gate::allows('viewChatQueue') || Gate::allows('acceptNextChat') || Gate::allows('manageChatAvailability'),
            403,
        );

        return Inertia::render('Chat/Console', [
            'console' => ChatConsoleState::for($request->user()),
            // Modal de encerramento (RF-34): categorias ativas e limites; só para quem encerra atendimentos.
            'closure' => $request->user()->hasPermission('chat.close') ? [
                'categories' => TicketCategory::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (TicketCategory $category) => ['value' => (string) $category->id, 'label' => $category->name])->all(),
                'limits' => [
                    'title' => Ticket::TITLE_MAX_LENGTH,
                    'summary' => Ticket::RESOLUTION_SUMMARY_MAX_LENGTH,
                    'note' => EndChatSessionRequest::NOTE_MAX_LENGTH,
                ],
            ] : null,
        ]);
    }

    /** O mesmo estado do painel em JSON, para atualizar sem recarregar a página. */
    public function consoleState(Request $request): JsonResponse
    {
        abort_unless(
            Gate::allows('viewChatQueue') || Gate::allows('acceptNextChat') || Gate::allows('manageChatAvailability'),
            403,
        );

        return response()->json(ChatConsoleState::for($request->user()));
    }
}
