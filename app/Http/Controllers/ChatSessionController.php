<?php

namespace App\Http\Controllers;

use App\Actions\Chat\CancelChatSession;
use App\Actions\Chat\ChatSessionConflict;
use App\Actions\Chat\EndChatSession;
use App\Actions\Chat\NoChatTechnicianAvailable;
use App\Actions\Chat\RequestChatSession;
use App\Http\Requests\Chat\EndChatSessionRequest;
use App\Http\Requests\Chat\StoreChatSessionRequest;
use App\Models\ChatSession;
use App\Support\ChatSessionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Sessões do solicitante (RF-26, RF-27, RF-32, RF-34), em JSON: a tela do chat (Etapa 7) atualiza sem recarregar.
 * Sessões de outras pessoas respondem 404, para não revelar quais identificadores existem.
 */
class ChatSessionController extends Controller
{
    /** 201 quando cria, 200 quando devolve a sessão aberta que já existia, 409 sem técnico de plantão. */
    public function store(StoreChatSessionRequest $request, RequestChatSession $requestSession): JsonResponse
    {
        try {
            [$session, $created] = $requestSession($request->user(), $request->validated('subject'));
        } catch (NoChatTechnicianAvailable $e) {
            // RN-CHAT-06: a alternativa é o chamado tradicional.
            return response()->json([
                'code' => 'no_technician_available',
                'message' => $e->getMessage(),
                'ticket_url' => route('tickets.create'),
            ], 409);
        }

        return response()->json(ChatSessionState::for($session, $request->user()), $created ? 201 : 200);
    }

    /** Sessão aberta (aguardando ou em atendimento) do solicitante, ou `session: null`: o botão flutuante consulta em qualquer página. */
    public function current(Request $request): JsonResponse
    {
        Gate::authorize('requestChat');

        $open = RequestChatSession::openSessionOf($request->user());

        return response()->json(['session' => $open ? ChatSessionState::for($open, $request->user()) : null]);
    }

    public function show(Request $request, int $session): JsonResponse
    {
        return response()->json(ChatSessionState::for($this->visible($request, $session), $request->user()));
    }

    public function cancel(Request $request, int $session, CancelChatSession $cancel): JsonResponse
    {
        try {
            $cancelled = $cancel($request->user(), $this->visible($request, $session));
        } catch (ChatSessionConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(ChatSessionState::for($cancelled, $request->user()));
    }

    /** Encerra o atendimento e gera o chamado (RF-34). 200 também na repetição: devolve o mesmo chamado. */
    public function end(EndChatSessionRequest $request, int $session, EndChatSession $end): JsonResponse
    {
        try {
            [$ended] = $end($request->user(), $this->visible($request, $session), $request->validated());
        } catch (ChatSessionConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(ChatSessionState::for($ended, $request->user()));
    }

    private function visible(Request $request, int $id): ChatSession
    {
        $session = ChatSession::find($id);

        abort_unless($session && Gate::allows('view', $session), 404);

        return $session;
    }
}
