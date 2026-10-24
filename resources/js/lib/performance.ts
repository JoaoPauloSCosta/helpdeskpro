import type { TicketStatus } from '@/lib/ticket';

export const performanceFilterKeys = ['opened_from', 'opened_to', 'technician', 'department', 'category', 'status', 'sla'] as const;
export type PerformanceFilterKey = (typeof performanceFilterKeys)[number];
export type PerformanceFilters = Partial<Record<PerformanceFilterKey, string>>;

export interface PerformanceAverage {
    count: number;
    /** Nulo quando nenhum chamado tem o dado: a tela explica, nunca mostra zero. */
    average_seconds: number | null;
}

/** Indicadores de uma linha (técnico) ou do total, calculados no backend (RF-25). */
export interface PerformanceSummary {
    attended: number;
    resolved: number;
    /** Chamados atendidos com SLA apurado (receberam política). */
    tracked: number;
    within_sla: number;
    violated_tickets: number;
    violations: number;
    first_response_violations: number;
    resolution_violations: number;
    /** Percentuais nulos quando não há chamado com SLA apurado. */
    compliance_rate: number | null;
    violation_rate: number | null;
    first_response: PerformanceAverage;
    resolution: PerformanceAverage;
}

export interface PerformanceTechnician extends PerformanceSummary {
    /** Nulo na linha "Sem responsável". */
    id: number | null;
    name: string;
    by_status: Record<TicketStatus | string, number>;
}

export interface PerformanceEvolutionPoint {
    /** Primeiro dia do intervalo (dia, semana ou mês). */
    start: string;
    opened: number;
    resolved: number;
    violated: number;
    compliance: number | null;
}

export interface PerformanceReport {
    totals: PerformanceSummary;
    statuses: { value: string; label: string; total: number }[];
    technicians: { data: PerformanceTechnician[]; current_page: number; per_page: number; total: number };
    evolution: PerformanceEvolutionPoint[];
}

export interface PerformancePeriod {
    from: string;
    to: string;
    is_default: boolean;
    granularity: 'day' | 'week' | 'month';
}

export interface PerformanceOptions {
    technicians: { value: string; label: string }[];
    departments: { value: string; label: string }[];
    categories: { value: string; label: string }[];
    statuses: { value: string; label: string }[];
    slaStates: { value: string; label: string }[];
}

export const percent = (value: number | null): string =>
    value === null ? '—' : `${value.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
