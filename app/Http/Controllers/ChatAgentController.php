<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\UpdateChatAvailabilityRequest;
use App\Models\ChatAgent;
use App\Support\ChatConsoleState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Estado do técnico no chat (RF-28, CHAT-04): disponibilidade manual, presença e disponibilidade efetiva.
 * Respostas em JSON, para o painel atualizar sem recarregar a página. Sempre sobre o próprio usuário.
 */
class ChatAgentController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('manageChatAvailability');

        return response()->json(ChatConsoleState::agent($request->user()));
    }

    /** Ligar a chave também conta como sinal de presença: quem acabou de ligá-la está com o painel aberto. */
    public function updateAvailability(UpdateChatAvailabilityRequest $request): JsonResponse
    {
        $available = $request->boolean('available');
        $agent = ChatAgent::forUser($request->user());

        $agent->forceFill(['is_available' => $available, ...($available ? ['last_seen_at' => now()] : [])])->save();

        return response()->json(ChatConsoleState::agent($request->user()));
    }

    /** Presença (D-C3): o painel chama a cada `chat.heartbeat_interval` segundos. */
    public function heartbeat(Request $request): Response
    {
        Gate::authorize('manageChatAvailability');

        ChatAgent::forUser($request->user())->forceFill(['last_seen_at' => now()])->save();

        return response()->noContent();
    }
}
