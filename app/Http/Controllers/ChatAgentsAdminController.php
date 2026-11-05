<?php

namespace App\Http\Controllers;

use App\Enums\ChatAvailability;
use App\Enums\ChatSessionStatus;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Equipe do chat e capacidade de cada técnico (CHAT-06, RF-28, RF-36). A gravação é `chat.admin.agents.update`. */
class ChatAgentsAdminController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manageChatCapacity');

        $technicians = User::where('is_active', true)
            ->whereHas('role.permissions', fn ($permission) => $permission->where('slug', 'chat.accept_next'))
            ->with('chatAgent')
            ->orderBy('name')
            ->get();

        $active = ChatSession::where('status', ChatSessionStatus::Active)
            ->selectRaw('technician_id, count(*) as total')->groupBy('technician_id')->pluck('total', 'technician_id');

        return Inertia::render('Chat/Agents', [
            'agents' => $technicians->map(function (User $user) use ($active) {
                $agent = $user->chatAgent;
                $agent?->setRelation('user', $user);
                $count = (int) ($active[$user->id] ?? 0);
                $availability = $agent?->availability($count) ?? ChatAvailability::Unavailable;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'availability' => ['value' => $availability->value, 'label' => $availability->label()],
                    'active_sessions' => $count,
                    'max_sessions' => $agent?->max_sessions ?? config('chat.default_capacity'),
                    'last_seen_at' => $agent?->last_seen_at?->toIso8601String(),
                ];
            })->values()->all(),
            'limits' => ['max' => config('chat.max_capacity')],
        ]);
    }
}
