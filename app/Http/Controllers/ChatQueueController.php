<?php

namespace App\Http\Controllers;

use App\Actions\Chat\AcceptNextChatSession;
use App\Actions\Chat\ChatAgentUnavailable;
use App\Actions\Chat\ChatCapacityReached;
use App\Actions\Chat\ChatQueueEmpty;
use App\Actions\Chat\ChatSessionConflict;
use App\Support\ChatConsoleState;
use App\Support\ChatSessionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Fila operacional (CHAT-04, RF-27, RF-29), em JSON para o painel do técnico. */
class ChatQueueController extends Controller
{
    /**
     * Fila em ordem FIFO. Só o que o painel precisa: posição, assunto, departamento e entrada. O técnico não
     * escolhe quem atender ("Atender próximo" pega o primeiro), então não há identificador da sessão.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewChatQueue');

        return response()->json(ChatConsoleState::queue());
    }

    /** 200 com a sessão assumida; 409 com o motivo quando não há o que assumir ou o técnico não pode. */
    public function next(Request $request, AcceptNextChatSession $accept): JsonResponse
    {
        Gate::authorize('acceptNextChat');

        try {
            $session = $accept($request->user());
        } catch (ChatQueueEmpty|ChatCapacityReached|ChatAgentUnavailable|ChatSessionConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(ChatSessionState::for($session, $request->user()));
    }
}
