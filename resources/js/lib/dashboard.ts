import type { TicketPriority, TicketStatus } from '@/lib/ticket';

/** Quantidade de chamados para um valor; `value` é o mesmo aceito pelo filtro da listagem (CHA-01). */
export interface DashboardCount<L extends string = string> {
    value: string;
    label: L;
    total: number;
}

/** Ranking decrescente; `others` soma os itens além dos exibidos. */
export interface DashboardRanking {
    items: DashboardCount[];
    others: { count: number; total: number } | null;
}

/** Indicadores do RF-20 calculados no backend, já restritos ao escopo de acesso e ao período. */
export interface DashboardMetrics {
    total: number;
    /** Os oito status, na ordem do ciclo de vida, inclusive os zerados. */
    byStatus: DashboardCount<TicketStatus>[];
    /** `null` na visão restrita do solicitante (RF-24). */
    byPriority: DashboardCount<TicketPriority>[] | null;
    byDepartment: DashboardRanking;
    /** `value` `none`: chamados sem responsável. */
    byAssignee: DashboardRanking;
    /** `null` na visão restrita do solicitante (RF-24). */
    sla: {
        /** Prazo de resolução vencido, em andamento ou na resolução. */
        overdue: number;
        /** Chamados com prazo de resolução gravado. Zero: o SLA ainda não foi apurado. */
        tracked: number;
    } | null;
    /** Chamados com ao menos uma reabertura. */
    reopened: number;
    /** Tempos médios em segundos; `average_seconds` nulo quando nenhum chamado tem o dado. */
    times: {
        /** Da abertura à primeira resposta pública da equipe. */
        first_response: { count: number; average_seconds: number | null };
        /** Tempo efetivo de resolução (sem pausas), dos chamados resolvidos ou encerrados. */
        resolution: { count: number; average_seconds: number | null };
    };
    pending: {
        /** Pendências encerradas e a soma das suas durações. */
        closed: number;
        closed_seconds: number;
        /** Pendências em andamento, ainda sem duração. */
        active: number;
    };
}

export const dashboardFilterKeys = ['opened_from', 'opened_to'] as const;

export type DashboardFilterKey = (typeof dashboardFilterKeys)[number];
export type DashboardFilters = Partial<Record<DashboardFilterKey, string>>;
