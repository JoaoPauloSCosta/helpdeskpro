<?php

namespace App\Http\Controllers;

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketSlaPause;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketSlaHistoryController extends Controller
{
    /** Eventos de auditoria que afetam ou documentam os relógios de SLA (RF-15 a RF-17). */
    private const EVENTS = [
        TicketEvent::PendingRegistered,
        TicketEvent::StatusChanged,
        TicketEvent::PriorityChanged,
        TicketEvent::Resolved,
        TicketEvent::Reopened,
        TicketEvent::Closed,
    ];

    /**
     * CHA-13 — Histórico de SLA do chamado, somente leitura.
     *
     * Tudo vem de registros gravados pelos fluxos operacionais: os períodos de pausa
     * (`ticket_sla_pauses`) e os eventos de auditoria (`ticket_histories`). Nada é gravado, recalculado
     * ou inferido do status atual. Ainda não há políticas nem motor de SLA (Etapa 7): meta, tempo
     * consumido e tempo restante não existem nos registros e por isso não são enviados.
     *
     * Quem acessa o chamado consulta o histórico; motivo, dados da pendência e autores ficam com a
     * equipe de suporte, como na pendência em andamento da CHA-03.
     */
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket);

        $support = $request->user()->can('viewPendingDetails', $ticket);

        $pauses = $ticket->slaPauses()
            ->with($support ? ['author:id,name', 'relatedDepartment:id,name'] : [])
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        $closed = $pauses->whereNotNull('ended_at');

        $events = $ticket->histories()
            ->whereIn('event_type', self::EVENTS)
            ->with($support ? ['author:id,name'] : [])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TicketHistory $event) => $this->event($event, $support))
            ->filter()
            ->values();

        return response()
            ->json([
                'ticket' => $ticket->ticket_number,
                'pauses' => [
                    'count' => $pauses->count(),
                    // Soma das durações gravadas na retomada; nula se nenhuma pausa foi encerrada.
                    'closed_seconds' => $closed->isEmpty() ? null : (int) $closed->sum('duration_seconds'),
                    'items' => $pauses
                        ->values()
                        ->map(fn (TicketSlaPause $pause, int $index) => $this->pause($pause, $index + 1, $support))
                        ->reverse()
                        ->values(),
                ],
                'events' => $events,
            ])
            // Motivos de pendência não devem ficar em caches intermediários.
            ->header('Cache-Control', 'no-store, private');
    }

    /** Um período de pausa (RF-17), numerado em ordem cronológica. */
    private function pause(TicketSlaPause $pause, int $number, bool $support): array
    {
        return [
            'number' => $number,
            'type' => $pause->pause_status->label(),
            'started_at' => $pause->started_at->toIso8601String(),
            'ended_at' => $pause->ended_at?->toIso8601String(),
            // Gravada somente na retomada; nula enquanto a pausa está em andamento.
            'duration_seconds' => $pause->duration_seconds,
            ...($support ? [
                'reason' => $pause->reason,
                'supplier_name' => $pause->supplier_name,
                'protocol_number' => $pause->protocol_number,
                'related_department' => $pause->relatedDepartment?->name,
                'responsible_name' => $pause->responsible_name,
                'expected_return_at' => $pause->expected_return_at?->toDateString(),
                'author' => $pause->author?->name,
            ] : []),
        ];
    }

    /**
     * Um evento da linha do tempo, a partir do que o fluxo gravou. Alterações de status que não
     * envolvem pausa não afetam os relógios e ficam de fora (retorna `null`).
     */
    private function event(TicketHistory $event, bool $support): ?array
    {
        $old = $event->old_values ?? [];
        $new = $event->new_values ?? [];

        $data = match ($event->event_type) {
            TicketEvent::PendingRegistered => ['kind' => 'pause_started', 'to' => $new['label'] ?? null],
            TicketEvent::StatusChanged => $this->isResume($old) ? [
                // Chamados pausados antes do CHA-08 não têm período registrado: a duração fica nula.
                'kind' => 'resumed',
                'from' => $old['label'] ?? null,
                'to' => $new['label'] ?? null,
                'duration_seconds' => $new['sla_pause']['duration_seconds'] ?? null,
                'pause_recorded' => isset($new['sla_pause']),
            ] : null,
            TicketEvent::PriorityChanged => [
                'kind' => 'priority_changed',
                'from' => $old['label'] ?? null,
                'to' => $new['label'] ?? null,
            ],
            TicketEvent::Resolved => [
                'kind' => 'resolved',
                'resolution_due_at' => $new['resolution_due_at'] ?? null,
                // Comparação dos instantes gravados no próprio evento; sem prazo gravado, não há apuração.
                'on_time' => isset($new['resolution_due_at'], $new['resolved_at'])
                    ? CarbonImmutable::parse($new['resolved_at'])->lte(CarbonImmutable::parse($new['resolution_due_at']))
                    : null,
            ],
            TicketEvent::Reopened => [
                'kind' => 'reopened',
                'first_response_due_at' => $new['first_response_due_at'] ?? null,
                'resolution_due_at' => $new['resolution_due_at'] ?? null,
            ],
            TicketEvent::Closed => ['kind' => 'closed'],
            default => null,
        };

        if ($data === null) {
            return null;
        }

        return [
            'id' => $event->id,
            'created_at' => $event->created_at?->toIso8601String(),
            ...($support ? ['author' => $event->author?->name] : []),
            ...$data,
        ];
    }

    /** Retomada (RF-15.2): saída de um dos quatro status que pausam o SLA de resolução. */
    private function isResume(array $old): bool
    {
        $from = TicketStatus::tryFrom($old['status'] ?? '');

        return $from !== null && in_array($from, TicketStatus::paused(), true);
    }
}
