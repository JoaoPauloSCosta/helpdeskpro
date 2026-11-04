<?php

namespace App\Http\Controllers;

use App\Support\ChatMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Indicadores do atendimento via chat (CHAT-08, RF-36), somente leitura. */
class ChatMetricsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewChatMetrics');

        $days = (int) $request->query('days', 30);
        $days = in_array($days, ChatMetrics::PERIODS, true) ? $days : 30;

        return Inertia::render('Chat/Metrics', [
            'metrics' => (new ChatMetrics($days, now()))->build(),
            'periods' => ChatMetrics::PERIODS,
        ]);
    }
}
