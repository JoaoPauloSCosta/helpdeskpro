<?php

namespace App\Http\Controllers;

use App\Enums\ChatSessionStatus;
use App\Models\ChatSession;
use App\Support\ChatSessionState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Histórico de atendimentos (CHAT-07, RF-36). Quem tem `chat.view_history` vê todas as sessões; os demais,
 * só as que participaram (solicitante ou técnico). Sessão de outra pessoa responde 404, sem revelar que existe.
 */
class ChatHistoryController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $all = $user->hasPermission('chat.view_history');
        abort_unless($all || $user->hasPermission('chat.request') || $user->hasPermission('chat.accept_next'), 403);

        $status = ChatSessionStatus::tryFrom((string) $request->query('status'));
        $search = $all ? trim((string) $request->query('q', '')) : '';

        $page = ChatSession::query()
            ->with(['requester:id,name', 'technician:id,name', 'ticket:id,ticket_number'])
            ->when(! $all, fn ($query) => $query->where(fn ($own) => $own->where('requester_id', $user->id)->orWhere('technician_id', $user->id)))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('subject', 'like', "%{$search}%")
                ->orWhereHas('requester', fn ($requester) => $requester->where('name', 'like', "%{$search}%"))))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Chat/History', [
            'sessions' => $page->getCollection()->map(fn (ChatSession $session) => [
                'id' => $session->id,
                'subject' => $session->subject,
                'requester' => $session->requester->name,
                'technician' => $session->technician?->name,
                'status' => ['value' => $session->status->value, 'label' => $session->status->label()],
                'outcome' => $session->outcome?->label(),
                'ticket_number' => $session->ticket?->ticket_number,
                'created_at' => $session->created_at->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'filters' => ['status' => $status?->value, 'q' => $search],
            'statuses' => array_map(fn (ChatSessionStatus $s) => ['value' => $s->value, 'label' => $s->label()], ChatSessionStatus::cases()),
            'canSearch' => $all,
        ]);
    }

    public function show(Request $request, int $session): Response
    {
        $found = ChatSession::find($session);
        abort_unless($found && Gate::allows('view', $found), 404);

        $found->load(['events.author:id,name', 'requester:id,name']);

        return Inertia::render('Chat/HistoryShow', [
            'session' => [
                ...ChatSessionState::for($found, $request->user()),
                // No histórico o próprio solicitante também aparece com o nome.
                'requester' => ['name' => $found->requester->name],
                'events' => $found->events->map(fn ($event) => [
                    'label' => $event->event_type->label(),
                    'author' => $event->author?->name,
                    'at' => $event->created_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }
}
