<?php

namespace App\Support;

use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Dados do painel do técnico (CHAT-04/05): disponibilidade, fila FIFO e atendimentos ativos. Cada bloco só
 * existe para quem tem a permissão correspondente; a tela não decide nada.
 */
class ChatConsoleState
{
    /** @return array<string, mixed> */
    public static function for(User $user): array
    {
        return [
            'agent' => Gate::forUser($user)->allows('manageChatAvailability') ? self::agent($user) : null,
            'queue' => Gate::forUser($user)->allows('viewChatQueue') ? self::queue() : null,
            'sessions' => Gate::forUser($user)->allows('acceptNextChat') || Gate::forUser($user)->allows('manageChatAvailability')
                ? self::sessions($user)
                : [],
        ];
    }

    /** @return array<string, mixed> */
    public static function agent(User $user): array
    {
        $agent = ChatAgent::forUser($user);
        $agent->setRelation('user', $user);
        $active = $agent->activeSessionsCount();
        $availability = $agent->availability($active);

        return [
            'is_available' => $agent->is_available,
            'availability' => ['value' => $availability->value, 'label' => $availability->label()],
            'active_sessions' => $active,
            'max_sessions' => $agent->max_sessions,
        ];
    }

    /**
     * Fila em ordem FIFO. Só o que o painel precisa: posição, assunto, departamento e entrada. O técnico não
     * escolhe quem atender ("Atender próximo" pega o primeiro), então não há identificador nem nome.
     *
     * @return array{waiting: int, entries: list<array<string, mixed>>}
     */
    public static function queue(): array
    {
        $entries = ChatSession::where('status', ChatSessionStatus::Waiting)
            ->orderBy('id')
            ->with('department:id,name')
            ->get(['id', 'subject', 'department_id', 'created_at'])
            ->values()
            ->map(fn (ChatSession $session, int $index) => [
                'position' => $index + 1,
                'subject' => $session->subject,
                'department' => $session->department->name,
                'waiting_since' => $session->created_at->toIso8601String(),
            ])
            ->all();

        return ['waiting' => count($entries), 'entries' => $entries];
    }

    /**
     * Atendimentos em andamento do técnico, do mais antigo ao mais novo, com as mensagens não lidas.
     *
     * @return list<array<string, mixed>>
     */
    public static function sessions(User $user): array
    {
        return ChatSession::where('technician_id', $user->id)
            ->where('status', ChatSessionStatus::Active)
            ->orderBy('accepted_at')->orderBy('id')
            ->get()
            ->map(fn (ChatSession $session) => [
                ...ChatSessionState::for($session, $user),
                'unread' => ChatMessage::where('chat_session_id', $session->id)
                    ->where('user_id', '!=', $user->id)
                    ->where('id', '>', $session->technician_last_read_id ?? 0)
                    ->count(),
            ])
            ->all();
    }
}
