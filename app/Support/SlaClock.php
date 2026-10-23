<?php

namespace App\Support;

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Cálculo e registro de violações do SLA em um único lugar (RF-15, RF-16, RN-12, RN-15). Só
 * recordBreaches() grava.
 *
 * O tempo de resolução consumido sai do prazo vigente: `meta aplicada − (resolution_due_at − instante)`.
 * Isso vale porque cada retomada e cada reabertura estendem `resolution_due_at`
 * pelo tempo parado, de modo que o prazo já embute todas as pausas encerradas.
 *
 * O "instante" é onde o relógio está parado, quando está:
 *  - pausa em andamento: o início dela (o prazo só é estendido na retomada);
 *  - resolvido ou encerrado: `resolved_at`;
 *  - demais casos: o instante informado.
 */
class SlaClock
{
    /** Política ativa da prioridade, ou `null` quando não há (RF-15.3, D2). */
    public static function policyFor(TicketPriority $priority): ?SlaPolicy
    {
        return SlaPolicy::query()
            ->where('priority', $priority->value)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Segundos de resolução já consumidos, sem as pausas. Nunca negativo; pode passar da meta
     * quando o chamado está vencido. `null` se o chamado não tem prazo ou meta aplicada.
     */
    public static function consumedResolutionSeconds(Ticket $ticket, CarbonInterface $now): ?int
    {
        $target = self::targetSeconds($ticket);
        $remaining = self::remainingResolutionSeconds($ticket, $now);

        return $target === null || $remaining === null ? null : max(0, $target - $remaining);
    }

    /**
     * Segundos até o prazo de resolução; negativo quando já venceu (RF-16.3).
     * `null` se o chamado não tem prazo.
     */
    public static function remainingResolutionSeconds(Ticket $ticket, CarbonInterface $now): ?int
    {
        $due = $ticket->resolution_due_at;

        if ($due === null) {
            return null;
        }

        return $due->getTimestamp() - self::clockInstant($ticket, $now)->getTimestamp();
    }

    /**
     * Registra a primeira violação de cada indicador (RN-15), no máximo uma vez por indicador.
     * Os campos `*_breached_at` nunca são apagados nem sobrescritos, então uma troca posterior para
     * uma meta maior não desfaz a violação. O chamado deve estar bloqueado (`lockForUpdate`) numa
     * transação aberta pelo chamador, com os prazos e a primeira resposta já atualizados.
     *
     * - Primeira resposta: violada se respondida depois do prazo, ou sem resposta e já vencida.
     * - Resolução: violada se o relógio (pausado, resolvido ou correndo) passou do prazo. Chamado
     *   pausado sem pausa registrada é ignorado, como na situação "Pausado".
     * Sem prazo (sem política), nada é registrado.
     *
     * @return list<string> Indicadores violados por esta chamada (`first_response`, `resolution`).
     */
    public static function recordBreaches(Ticket $ticket, CarbonInterface $now): array
    {
        $recorded = [];

        $firstResponseDue = $ticket->first_response_due_at;
        if ($ticket->first_response_breached_at === null && $firstResponseDue !== null
            && ($ticket->first_response_at ?? $now)->gt($firstResponseDue)) {
            $recorded[] = self::breach($ticket, 'first_response', $firstResponseDue, $now);
        }

        $resolutionDue = $ticket->resolution_due_at;
        if ($ticket->resolution_breached_at === null && $resolutionDue !== null && self::clockRuns($ticket)
            && self::remainingResolutionSeconds($ticket, $now) < 0) {
            $recorded[] = self::breach($ticket, 'resolution', $resolutionDue, $now);
        }

        return $recorded;
    }

    /**
     * Tempo efetivo de resolução decorrido, a partir dos fatos registrados: da abertura até o
     * relógio, menos as pausas encerradas e menos o tempo em `Resolvido` de cada reabertura (D3).
     * Serve para chamados sem meta aplicada, que não têm prazo de onde tirar o consumo (RF-16 ao
     * trocar para uma prioridade com política). Nunca negativo.
     */
    public static function effectiveElapsedSeconds(Ticket $ticket, CarbonInterface $now): int
    {
        $elapsed = self::clockInstant($ticket, $now)->getTimestamp() - $ticket->created_at->getTimestamp();

        $paused = (int) $ticket->slaPauses()->whereNotNull('ended_at')->sum('duration_seconds');

        $resolvedGaps = $ticket->histories()
            ->where('event_type', TicketEvent::Reopened)
            ->get(['old_values', 'new_values'])
            ->sum(function ($event) {
                $resolvedAt = $event->old_values['resolved_at'] ?? null;
                $reopenedAt = $event->new_values['reopened_at'] ?? null;

                return $resolvedAt && $reopenedAt
                    ? max(0, Carbon::parse($reopenedAt)->getTimestamp() - Carbon::parse($resolvedAt)->getTimestamp())
                    : 0;
            });

        return max(0, $elapsed - $paused - $resolvedGaps);
    }

    /** Meta de resolução aplicada ao chamado, em segundos; `null` sem política. */
    public static function targetSeconds(Ticket $ticket): ?int
    {
        return $ticket->sla_resolution_minutes === null ? null : $ticket->sla_resolution_minutes * 60;
    }

    private static function breach(Ticket $ticket, string $indicator, CarbonInterface $due, CarbonInterface $now): string
    {
        $ticket->forceFill(["{$indicator}_breached_at" => $now])->save();

        // Registro do sistema: quem agiu no chamado detectou a violação, mas não a causou.
        $ticket->histories()->forceCreate([
            'user_id' => null,
            'event_type' => TicketEvent::SlaBreached,
            'old_values' => null,
            'new_values' => [
                'indicator' => $indicator,
                'due_at' => $due->toIso8601String(),
                'breached_at' => $now->toIso8601String(),
            ],
            'created_at' => $now,
        ]);

        return $indicator;
    }

    /** Falso para chamado pausado sem pausa registrada (anterior ao CHA-08): não há como apurar. */
    private static function clockRuns(Ticket $ticket): bool
    {
        return ! in_array($ticket->status, TicketStatus::paused(), true) || $ticket->activeSlaPause !== null;
    }

    /**
     * Instante em que o relógio de resolução está. Chamados pausados sem pausa registrada
     * (anteriores ao CHA-08) seguem o instante informado: não há de onde tirar o início da pausa.
     */
    public static function clockInstant(Ticket $ticket, CarbonInterface $now): CarbonInterface
    {
        if (in_array($ticket->status, TicketStatus::paused(), true)) {
            return $ticket->activeSlaPause?->started_at ?? $now;
        }

        if (in_array($ticket->status, TicketStatus::finished(), true)) {
            return $ticket->resolved_at ?? $now;
        }

        return $now;
    }
}
