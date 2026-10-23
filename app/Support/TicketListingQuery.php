<?php

namespace App\Support;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filtros (RF-07) e ordenação da consulta de chamados, compartilhados pela listagem (CHA-01) e pela
 * exportação (EXP-01) para que as duas tenham exatamente a mesma semântica. Não aplica o escopo de
 * acesso: quem chama deve começar por `Ticket::visibleTo($user)`.
 */
class TicketListingQuery
{
    public static function filter(Builder $query, array $filters, CarbonInterface $now): void
    {
        $like = fn (string $value) => '%'.addcslashes($value, '%_\\').'%';

        if (isset($filters['q'])) {
            $query->where(fn (Builder $query) => $query
                ->where('tickets.ticket_number', 'like', $like(mb_strtoupper($filters['q'])))
                ->orWhere('tickets.title', 'like', $like($filters['q'])));
        }

        if (isset($filters['number'])) {
            $query->where('tickets.ticket_number', 'like', $like(mb_strtoupper($filters['number'])));
        }

        if (isset($filters['title'])) {
            $query->where('tickets.title', 'like', $like($filters['title']));
        }

        foreach (['requester' => 'requester_id', 'department' => 'department_id', 'category' => 'category_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where("tickets.$column", (int) $filters[$key]);
            }
        }

        if (isset($filters['priority'])) {
            $query->where('tickets.priority', $filters['priority']);
        }

        if (isset($filters['status'])) {
            $query->where('tickets.status', $filters['status']);
        }

        if (isset($filters['assignee'])) {
            $filters['assignee'] === 'none'
                ? $query->whereNull('tickets.assigned_to')
                : $query->where('tickets.assigned_to', (int) $filters['assignee']);
        }

        // As datas do filtro são dias completos no fuso da aplicação.
        if (isset($filters['opened_from'])) {
            $query->where('tickets.created_at', '>=', Carbon::parse($filters['opened_from'])->startOfDay());
        }

        if (isset($filters['opened_to'])) {
            $query->where('tickets.created_at', '<=', Carbon::parse($filters['opened_to'])->endOfDay());
        }

        if (isset($filters['sla'])) {
            $query->whereSlaState(SlaState::from($filters['sla']), $now);
        }
    }

    public static function sort(Builder $query, string $sort, string $direction): void
    {
        // Prioridade e status seguem a ordem de domínio dos enums, não a ordem alfabética.
        $rank = function (string $column, array $cases) use ($query, $direction) {
            $sql = "CASE $column ".str_repeat('WHEN ? THEN ? ', count($cases))."END $direction";
            $bindings = collect($cases)->flatMap(fn ($case, $i) => [$case->value, $i])->all();
            $query->orderByRaw($sql, $bindings);
        };

        match ($sort) {
            'number' => $query->orderBy('tickets.ticket_number', $direction),
            'title' => $query->orderBy('tickets.title', $direction),
            'priority' => $rank('tickets.priority', TicketPriority::cases()),
            'status' => $rank('tickets.status', TicketStatus::cases()),
            'assignee' => $query->orderBy(
                User::select('name')->whereColumn('users.id', 'tickets.assigned_to'),
                $direction,
            ),
            'created_at' => $query->orderBy('tickets.created_at', $direction),
            'updated_at' => $query->orderBy('tickets.updated_at', $direction),
        };

        // Critério de desempate estável para a paginação.
        $query->orderBy('tickets.id', $direction);
    }
}
