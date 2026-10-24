<?php

namespace App\Support;

use App\Enums\SlaState;
use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Painel de desempenho dos técnicos (RF-25), somente leitura.
 *
 * Não cria regra de SLA: usa os campos gravados pelo motor (`*_breached_at`, `resolution_effective_seconds`,
 * metas copiadas no chamado, pausas já refletidas nos prazos) e os estados de `Ticket::slaState()` e
 * `firstResponseSlaState()`. Pausa não vira violação sem que o SLA a determine, e violações anteriores a uma
 * reabertura continuam contadas, porque `*_breached_at` nunca é apagado (RN-15).
 *
 * Definições (também exibidas na tela):
 *  - Atendidos: chamados abertos no período cujo responsável atual é o técnico.
 *  - Resolvidos: chamados resolvidos no período (hoje resolvidos ou encerrados) cuja resolução mais recente
 *    foi registrada pelo técnico. O responsável no momento da resolução não é gravado; o autor do evento
 *    de resolução é a referência estável.
 *  - Com SLA apurado: chamados atendidos que receberam política (meta copiada). Os demais ficam fora do
 *    percentual, nunca como "dentro do prazo".
 *  - Violados: chamados atendidos com violação registrada ou prazo vencido agora, em qualquer indicador.
 *  - Violações: quantidade de indicadores violados (primeira resposta e resolução contam à parte).
 * Cada chamado conta uma vez por técnico, e uma única vez no total, não importa quantas trocas de
 * responsável ou de status tenha tido. Os escopos de acesso vêm de `Ticket::visibleTo()`.
 */
class PerformanceReport
{
    public const PER_PAGE = 15;

    private const DEFAULT_DAYS = 30;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    private bool $defaultPeriod;

    /** @param array<string, string> $filters Já validados por PerformanceRequest. */
    public function __construct(private User $user, private array $filters, private CarbonInterface $now)
    {
        $today = CarbonImmutable::instance($now)->startOfDay();

        $this->defaultPeriod = ! isset($filters['opened_from']) && ! isset($filters['opened_to']);
        $this->to = isset($filters['opened_to']) ? CarbonImmutable::parse($filters['opened_to'])->endOfDay() : $today->endOfDay();
        $this->from = isset($filters['opened_from'])
            ? CarbonImmutable::parse($filters['opened_from'])->startOfDay()
            : $this->to->subDays(self::DEFAULT_DAYS - 1)->startOfDay();
    }

    /** @return array{from: string, to: string, is_default: bool, granularity: string} */
    public function period(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'is_default' => $this->defaultPeriod,
            'granularity' => $this->granularity(),
        ];
    }

    /**
     * @return array{totals: array, technicians: array, evolution: list<array>, statuses: list<array>}|null
     *                                                                                                     `null` quando não há nenhum chamado no período e nos filtros.
     */
    public function build(int $page): ?array
    {
        $attended = $this->attendedTickets();
        $resolved = $this->resolvedTickets();

        if ($attended->isEmpty() && $resolved->isEmpty()) {
            return null;
        }

        $rows = [];
        $totals = $this->emptyStats();
        $statuses = array_fill_keys(array_map(fn (TicketStatus $s) => $s->value, TicketStatus::cases()), 0);
        $buckets = $this->emptyBuckets();

        foreach ($attended as $ticket) {
            $key = $ticket->assigned_to ?? 0;
            $rows[$key] ??= $this->emptyStats();
            $facts = $this->slaFacts($ticket);

            $count = function (array &$stats) use ($ticket, $facts) {
                $stats['attended']++;
                $stats['by_status'][$ticket->status->value]++;
                $stats['tracked'] += $facts['tracked'] ? 1 : 0;
                $stats['violated'] += $facts['violations'] > 0 ? 1 : 0;
                $stats['within'] += $facts['tracked'] && $facts['violations'] === 0 ? 1 : 0;
                $stats['violations'] += $facts['violations'];
                $stats['first_response_violations'] += $facts['first_response'] ? 1 : 0;
                $stats['resolution_violations'] += $facts['resolution'] ? 1 : 0;

                if ($ticket->first_response_at !== null) {
                    $stats['fr_count']++;
                    $stats['fr_sum'] += max(0, $ticket->first_response_at->getTimestamp() - $ticket->created_at->getTimestamp());
                }
            };

            // Uma vez por técnico e uma vez no total, qualquer que seja o histórico de trocas do chamado.
            $count($rows[$key]);
            $count($totals);

            $statuses[$ticket->status->value]++;

            $bucket = $this->bucketKey($ticket->created_at);
            if (isset($buckets[$bucket])) {
                $buckets[$bucket]['opened']++;
                $buckets[$bucket]['tracked'] += $facts['tracked'] ? 1 : 0;
                $buckets[$bucket]['within'] += $facts['tracked'] && $facts['violations'] === 0 ? 1 : 0;
                $buckets[$bucket]['violated'] += $facts['violations'] > 0 ? 1 : 0;
            }
        }

        foreach ($resolved as $ticket) {
            $key = $ticket->resolver_id ?? 0;
            $rows[$key] ??= $this->emptyStats();

            $count = function (array &$stats) use ($ticket) {
                $stats['resolved']++;

                if ($ticket->resolution_effective_seconds !== null) {
                    $stats['res_count']++;
                    $stats['res_sum'] += $ticket->resolution_effective_seconds;
                }
            };

            $count($rows[$key]);
            $count($totals);

            $bucket = $this->bucketKey($ticket->resolved_at);
            if (isset($buckets[$bucket])) {
                $buckets[$bucket]['resolved']++;
            }
        }

        $names = User::whereKey(array_filter(array_keys($rows)))->pluck('name', 'id');

        $technicians = collect($rows)
            ->map(fn (array $stats, int $key) => $this->row($stats, $key, $names[$key] ?? ($key === 0 ? 'Sem responsável' : 'Usuário removido')))
            // "Sem responsável" só entra com chamados abertos no período; resolvidos sem autor não viram linha.
            ->reject(fn (array $row) => $row['id'] === null && $row['attended'] === 0)
            ->sort(fn (array $a, array $b) => [$a['id'] === null, -$a['resolved'], -$a['attended'], $a['name']] <=> [$b['id'] === null, -$b['resolved'], -$b['attended'], $b['name']])
            ->values();

        return [
            'totals' => $this->summary($totals),
            'statuses' => array_map(fn (TicketStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'total' => $statuses[$s->value],
            ], TicketStatus::cases()),
            'technicians' => [
                'data' => $technicians->forPage($page, self::PER_PAGE)->values()->all(),
                'current_page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $technicians->count(),
            ],
            'evolution' => array_values(array_map(fn (array $bucket) => [
                'start' => $bucket['start'],
                'opened' => $bucket['opened'],
                'resolved' => $bucket['resolved'],
                'violated' => $bucket['violated'],
                'compliance' => $bucket['tracked'] ? round($bucket['within'] / $bucket['tracked'] * 100, 1) : null,
            ], $buckets)),
        ];
    }

    /** Chamados abertos no período, no escopo do usuário e nos filtros. @return Collection<int, Ticket> */
    private function attendedTickets(): Collection
    {
        return $this->base()
            ->whereBetween('tickets.created_at', [$this->from, $this->to])
            ->when(isset($this->filters['technician']), fn (Builder $q) => $q->where('tickets.assigned_to', (int) $this->filters['technician']))
            ->select([
                'tickets.id', 'tickets.assigned_to', 'tickets.status', 'tickets.created_at', 'tickets.sla_policy_id',
                'tickets.first_response_at', 'tickets.first_response_due_at', 'tickets.first_response_breached_at',
                'tickets.resolution_due_at', 'tickets.resolution_breached_at', 'tickets.resolved_at',
                'tickets.sla_first_response_minutes', 'tickets.sla_resolution_minutes',
            ])
            ->get();
    }

    /** Chamados resolvidos no período, com o autor da resolução mais recente. @return Collection<int, Ticket> */
    private function resolvedTickets(): Collection
    {
        $resolver = TicketHistory::query()
            ->select('ticket_histories.user_id')
            ->whereColumn('ticket_histories.ticket_id', 'tickets.id')
            ->where('ticket_histories.event_type', TicketEvent::Resolved)
            ->orderByDesc('ticket_histories.id')
            ->limit(1);

        $tickets = $this->base()
            ->whereIn('tickets.status', array_map(fn (TicketStatus $s) => $s->value, TicketStatus::finished()))
            ->whereBetween('tickets.resolved_at', [$this->from, $this->to])
            ->select(['tickets.id', 'tickets.resolved_at', 'tickets.resolution_effective_seconds'])
            ->selectSub($resolver, 'resolver_id')
            ->get();

        return isset($this->filters['technician'])
            ? $tickets->filter(fn (Ticket $t) => (int) $t->resolver_id === (int) $this->filters['technician'])->values()
            : $tickets;
    }

    /** Escopo de acesso primeiro; depois os filtros de departamento, categoria, status e SLA. */
    private function base(): Builder
    {
        return Ticket::query()
            ->visibleTo($this->user)
            ->when(isset($this->filters['department']), fn (Builder $q) => $q->where('tickets.department_id', (int) $this->filters['department']))
            ->when(isset($this->filters['category']), fn (Builder $q) => $q->where('tickets.category_id', (int) $this->filters['category']))
            ->when(isset($this->filters['status']), fn (Builder $q) => $q->where('tickets.status', $this->filters['status']))
            ->when(isset($this->filters['sla']), fn (Builder $q) => $q->whereSlaState(SlaState::from($this->filters['sla']), $this->now));
    }

    /**
     * Situação de SLA de um chamado, pelos mesmos estados do restante do sistema. A violação registrada
     * (histórica) vale mesmo que a meta atual esteja de novo no prazo.
     *
     * @return array{tracked: bool, first_response: bool, resolution: bool, violations: int}
     */
    private function slaFacts(Ticket $ticket): array
    {
        $first = $ticket->first_response_breached_at !== null || $ticket->firstResponseSlaState($this->now) === SlaState::Overdue;
        $resolution = $ticket->resolution_breached_at !== null || $ticket->slaState($this->now) === SlaState::Overdue;

        return [
            'tracked' => $ticket->sla_policy_id !== null,
            'first_response' => $first,
            'resolution' => $resolution,
            'violations' => ($first ? 1 : 0) + ($resolution ? 1 : 0),
        ];
    }

    private function emptyStats(): array
    {
        return [
            'attended' => 0, 'resolved' => 0, 'tracked' => 0, 'within' => 0, 'violated' => 0, 'violations' => 0,
            'first_response_violations' => 0, 'resolution_violations' => 0,
            'fr_count' => 0, 'fr_sum' => 0, 'res_count' => 0, 'res_sum' => 0,
            'by_status' => array_fill_keys(array_map(fn (TicketStatus $s) => $s->value, TicketStatus::cases()), 0),
        ];
    }

    /** Indicadores de uma linha; médias nulas quando não há dado válido (nunca zero inventado). */
    private function summary(array $stats): array
    {
        return [
            'attended' => $stats['attended'],
            'resolved' => $stats['resolved'],
            'tracked' => $stats['tracked'],
            'within_sla' => $stats['within'],
            'violated_tickets' => $stats['violated'],
            'violations' => $stats['violations'],
            'first_response_violations' => $stats['first_response_violations'],
            'resolution_violations' => $stats['resolution_violations'],
            'compliance_rate' => $stats['tracked'] ? round($stats['within'] / $stats['tracked'] * 100, 1) : null,
            'violation_rate' => $stats['tracked'] ? round($stats['violated'] / $stats['tracked'] * 100, 1) : null,
            'first_response' => [
                'count' => $stats['fr_count'],
                'average_seconds' => $stats['fr_count'] ? (int) round($stats['fr_sum'] / $stats['fr_count']) : null,
            ],
            'resolution' => [
                'count' => $stats['res_count'],
                'average_seconds' => $stats['res_count'] ? (int) round($stats['res_sum'] / $stats['res_count']) : null,
            ],
        ];
    }

    private function row(array $stats, int $key, string $name): array
    {
        return [
            'id' => $key === 0 ? null : $key,
            'name' => $name,
            ...$this->summary($stats),
            'by_status' => $stats['by_status'],
        ];
    }

    /** Granularidade da evolução, conforme o tamanho do período. */
    private function granularity(): string
    {
        $days = $this->from->diffInDays($this->to) + 1;

        return $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');
    }

    private function bucketKey(CarbonInterface $at): string
    {
        $at = CarbonImmutable::instance($at);

        return match ($this->granularity()) {
            'day' => $at->toDateString(),
            'week' => $at->startOfWeek()->toDateString(),
            'month' => $at->startOfMonth()->toDateString(),
        };
    }

    /** Todos os intervalos do período, também os sem chamados, para a evolução não ter lacunas. */
    private function emptyBuckets(): array
    {
        $step = match ($this->granularity()) {
            'day' => '1 day',
            'week' => '1 week',
            'month' => '1 month',
        };

        $buckets = [];
        $cursor = CarbonImmutable::parse($this->bucketKey($this->from));

        while ($cursor->lte($this->to)) {
            $buckets[$cursor->toDateString()] = ['start' => $cursor->toDateString(), 'opened' => 0, 'resolved' => 0, 'violated' => 0, 'tracked' => 0, 'within' => 0];
            $cursor = $cursor->modify("+{$step}");
        }

        return $buckets;
    }
}
