import type { Tone } from '@/lib/ui';

/** Os oito status do RF-10. O texto do badge é sempre exibido junto da cor. */
export const ticketStatuses = [
    'Aberto',
    'Em atendimento',
    'Aguardando solicitante',
    'Pendente',
    'Pendente Terceiros',
    'Pendente Departamentos',
    'Resolvido',
    'Encerrado',
] as const;

export type TicketStatus = (typeof ticketStatuses)[number];

export const statusTone: Record<TicketStatus, Tone> = {
    Aberto: 'info',
    'Em atendimento': 'primary',
    'Aguardando solicitante': 'warning',
    Pendente: 'warning',
    'Pendente Terceiros': 'warning',
    'Pendente Departamentos': 'warning',
    Resolvido: 'success',
    Encerrado: 'neutral',
};

/** Status que pausam o SLA de resolução (RF-15.2). */
export const pausedStatuses: TicketStatus[] = [
    'Aguardando solicitante',
    'Pendente',
    'Pendente Terceiros',
    'Pendente Departamentos',
];

/** Nomes ainda não confirmados no PRD (design-system.md, seção 3.2). */
export const ticketPriorities = ['Baixa', 'Média', 'Alta', 'Crítica'] as const;

export type TicketPriority = (typeof ticketPriorities)[number];

export const priorityTone: Record<TicketPriority, Tone> = {
    Baixa: 'neutral',
    Média: 'info',
    Alta: 'warning',
    Crítica: 'danger',
};

export const priorityGlyph: Record<TicketPriority, string> = {
    Baixa: '▽',
    Média: '◇',
    Alta: '△',
    Crítica: '▲',
};

export type SlaState = 'on_track' | 'at_risk' | 'overdue' | 'paused' | 'met';

/** Opção de filtro vinda do backend. */
export interface Option {
    value: string;
    label: string;
}

/** Linha da listagem (CHA-01). Nunca traz a chave primária interna. */
export interface TicketListItem {
    number: string;
    title: string;
    requester: string | null;
    department: string | null;
    category: string | null;
    priority: TicketPriority;
    status: TicketStatus;
    assignee: string | null;
    created_at: string | null;
    updated_at: string | null;
    /** Calculado no backend. `none`: o chamado ainda não tem prazo de resolução. */
    sla: SlaState | 'none';
    /** Exibição do botão "Assumir" (CHA-06); o backend revalida ao assumir. */
    can_self_assign: boolean;
}

/** Dados do solicitante na abertura (CHA-02). Somente leitura, vindos do backend. */
export interface TicketRequester {
    name: string;
    email: string;
    department: string | null;
    departmentActive: boolean;
}

/** Detalhes do chamado (CHA-03). Nunca traz a chave primária interna. */
export interface TicketDetail {
    number: string;
    title: string;
    description: string;
    requester: string | null;
    department: string | null;
    category: string | null;
    priority: TicketPriority;
    status: TicketStatus;
    assignee: string | null;
    created_at: string | null;
    updated_at: string | null;
}

/** Um indicador de SLA calculado no backend. `none`: ainda não há prazo gravado. */
export interface TicketSlaIndicator {
    state: Exclude<SlaState, 'at_risk'> | 'none';
    due_at: string | null;
    /** Instante em que a meta foi atingida (primeira resposta ou resolução). */
    met_at: string | null;
}

/** Mensagem do chamado (RF-11). Notas internas só chegam a quem pode lê-las. */
export interface TicketCommentItem {
    id: number;
    author: string | null;
    body: string;
    internal: boolean;
    created_at: string | null;
}

/** Técnico responsável ou elegível para atribuição (CHA-05). */
export interface TicketTechnician {
    id: number;
    name: string;
    department: string | null;
}

/** Evento de auditoria (RF-19), enviado somente a quem tem `audit.view`. */
export interface TicketHistoryItem {
    id: number;
    title: string;
    author: string | null;
    from: string | null;
    to: string | null;
    created_at: string | null;
}

export const ticketFilterKeys = [
    'q', 'number', 'title', 'requester', 'department', 'category',
    'priority', 'status', 'assignee', 'opened_from', 'opened_to', 'sla',
] as const;

export type TicketFilterKey = (typeof ticketFilterKeys)[number];
export type TicketFilters = Partial<Record<TicketFilterKey, string>>;

export type TicketSortKey = 'number' | 'title' | 'priority' | 'status' | 'assignee' | 'created_at' | 'updated_at';

export interface TicketFilterOptions {
    statuses: Option[];
    priorities: Option[];
    slaStates: Option[];
    departments: Option[];
    categories: Option[];
    requesters: Option[];
    assignees: Option[];
}

const dateTime = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' });

/** Data e hora no fuso do navegador (ex.: 21/10/2026 09:12). */
export const formatDateTime = (iso: string | null): string => (iso ? dateTime.format(new Date(iso)) : '—');

/** Converte AAAA-MM-DD em DD/MM/AAAA sem conversão de fuso. */
export const formatDate = (ymd: string): string => ymd.split('-').reverse().join('/');
