<?php

namespace App\Http\Controllers;

use App\Actions\Chat\ChatSessionConflict;
use App\Actions\Chat\SendChatMessage;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Policies\ChatSessionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Mensagens de uma sessão (RF-30, RF-33), em JSON. A leitura incremental (`after_id`) é o que a tela usa ao
 * abrir e ao reconectar: busca só o que ainda não tem, e o `id` evita duplicar o que chegou em tempo real.
 * Sessões de outras pessoas respondem 404.
 */
class ChatMessageController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(Request $request, int $session): JsonResponse
    {
        $chat = $this->visible($session);
        $after = max(0, (int) $request->query('after_id', 0));

        $messages = $chat->messages()->with('author:id,name')->where('id', '>', $after)
            ->orderBy('id')->limit(self::PAGE_SIZE + 1)->get();

        return response()->json([
            'messages' => $messages->take(self::PAGE_SIZE)->map(fn (ChatMessage $message) => $message->toPayload())->values(),
            'has_more' => $messages->count() > self::PAGE_SIZE,
        ]);
    }

    /** 201 gravada; 200 reenvio de uma já gravada; 409 sessão fora de atendimento; 403 sem permissão. */
    public function store(StoreChatMessageRequest $request, int $session, SendChatMessage $send): JsonResponse
    {
        try {
            [$message, $created] = $send(
                $request->user(),
                $this->visible($session),
                $request->validated('body'),
                $request->validated('client_id'),
            );
        } catch (ChatSessionConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($message->toPayload(), $created ? 201 : 200);
    }

    /** Marca como lidas as mensagens até `last_id`, para o contador de não lidas (CHAT-04). Nunca volta atrás. */
    public function read(Request $request, int $session): Response
    {
        $chat = $this->visible($session);
        $user = $request->user();

        abort_unless(ChatSessionPolicy::isParticipant($user, $chat), 403);

        $lastId = min((int) $request->validate(['last_id' => ['required', 'integer', 'min:1']])['last_id'], (int) $chat->messages()->max('id'));
        $column = $chat->requester_id === $user->id ? 'requester_last_read_id' : 'technician_last_read_id';

        if ($lastId < 1) {
            return response()->noContent();
        }

        ChatSession::whereKey($chat->id)
            ->where(fn ($query) => $query->whereNull($column)->orWhere($column, '<', $lastId))
            ->update([$column => $lastId]);

        return response()->noContent();
    }

    private function visible(int $id): ChatSession
    {
        $session = ChatSession::find($id);

        abort_unless($session && Gate::allows('view', $session), 404);

        return $session;
    }
}
