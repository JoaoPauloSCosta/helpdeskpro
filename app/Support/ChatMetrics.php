<?php

namespace App\Support;

use App\Enums\ChatOutcome;
use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Indicadores do chat (CHAT-08, RF-36, D-C9). Calculados no servidor, só a partir de fatos gravados, para as
 * sessões **pedidas** no período (últimos N dias). Tempos em segundos; sem dados, `null` (nunca zero).
 *
 * Fórmulas (docs/chat/09-administracao-historico-indicadores.md):
 * - Espera na fila: `accepted_at − created_at`, das sessões que foram atendidas.
 * - Primeira resposta: `first_response_at − accepted_at`, das sessões em que o técnico escreveu (mínimo 0).
 * - Duração: `ended_at − accepted_at`, das encerradas.
 * - % resolvidas: resolvidas ÷ encerradas. % canceladas: canceladas ÷ pedidas.
 * - Fila e atendimentos "agora" não dependem do período.
 *
 * shortcut: lê as linhas do período e agrega em PHP (portável entre SQLite e MariaDB); com centenas de
 * milhares de sessões, mover as médias para SQL.
 */
class ChatMetrics
{
    public const PERIODS = [7, 30, 90];

    public function __construct(private int $days, private CarbonInterface $now) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $from = $this->now->copy()->subDays($this->days);
        $sessions = ChatSession::where('created_at', '>=', $from)
            ->get(['id', 'status', 'outcome', 'technician_id', 'created_at', 'accepted_at', 'first_response_at', 'ended_at']);

        $ended = $sessions->where('status', ChatSessionStatus::Ended);
        $cancelled = $sessions->where('status', ChatSessionStatus::Cancelled);
        $resolved = $ended->where('outcome', ChatOutcome::Resolved);

        return [
            'days' => $this->days,
            'from' => $from->toIso8601String(),
            'generated_at' => $this->now->toIso8601String(),
            'requested' => $sessions->count(),
            'ended' => $ended->count(),
            'cancelled' => $cancelled->count(),
            'resolved' => $resolved->count(),
            'unresolved' => $ended->count() - $resolved->count(),
            'resolved_rate' => self::rate($resolved->count(), $ended->count()),
            'cancelled_rate' => self::rate($cancelled->count(), $sessions->count()),
            'avg_wait_seconds' => self::average($sessions, 'created_at', 'accepted_at'),
            'avg_first_response_seconds' => self::average($sessions, 'accepted_at', 'first_response_at'),
            'avg_duration_seconds' => self::average($ended, 'accepted_at', 'ended_at'),
            'now' => [
                'waiting' => ChatSession::where('status', ChatSessionStatus::Waiting)->count(),
                'active' => ChatSession::where('status', ChatSessionStatus::Active)->count(),
            ],
            'technicians' => $this->technicians($sessions),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function technicians(Collection $sessions): array
    {
        $bySession = $sessions->whereNotNull('technician_id')->groupBy('technician_id');
        $activeNow = ChatSession::where('status', ChatSessionStatus::Active)
            ->selectRaw('technician_id, count(*) as total')->groupBy('technician_id')->pluck('total', 'technician_id');
        $ids = $bySession->keys()->merge($activeNow->keys())->unique()->values();
        $capacity = ChatAgent::pluck('max_sessions', 'user_id');
        $names = User::whereIn('id', $ids)->pluck('name', 'id');

        return $ids
            ->map(function ($id) use ($bySession, $activeNow, $capacity, $names) {
                $own = $bySession->get($id, collect());
                $ended = $own->where('status', ChatSessionStatus::Ended);

                return [
                    'id' => (int) $id,
                    'name' => $names[$id] ?? 'Usuário removido',
                    'attended' => $own->whereNotNull('accepted_at')->count(),
                    'ended' => $ended->count(),
                    'resolved_rate' => self::rate($ended->where('outcome', ChatOutcome::Resolved)->count(), $ended->count()),
                    'avg_first_response_seconds' => self::average($own, 'accepted_at', 'first_response_at'),
                    'avg_duration_seconds' => self::average($ended, 'accepted_at', 'ended_at'),
                    'active_now' => (int) ($activeNow[$id] ?? 0),
                    'max_sessions' => (int) ($capacity[$id] ?? config('chat.default_capacity')),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    private static function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part * 100 / $whole, 1);
    }

    /** Média em segundos entre dois instantes, só das linhas que têm os dois; negativos contam como 0. */
    private static function average(Collection $sessions, string $start, string $end): ?int
    {
        $seconds = $sessions->filter(fn ($s) => $s->{$start} !== null && $s->{$end} !== null)
            ->map(fn ($s) => max(0, $s->{$end}->getTimestamp() - $s->{$start}->getTimestamp()));

        return $seconds->isEmpty() ? null : (int) round($seconds->avg());
    }
}
