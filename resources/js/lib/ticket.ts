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

/** Destino da alteração de status (CHA-07). Calculado no backend, que revalida ao gravar. */
export interface TicketStatusTarget {
    /** Valor técnico enviado ao servidor. */
    value: string;
    label: TicketStatus;
    /** Efeito da transição, quando conhecido. */
    description: string | null;
}

/** Alteração de status (CHA-07), enviada somente a quem tem `tickets.change_status`. */
export interface TicketStatusChange {
    /** Valor técnico do status atual, reenviado para detectar alterações concorrentes. */
    current: string;
    /** Vazio quando não há transição executável no estado atual. */
    targets: TicketStatusTarget[];
}

/** Campo específico do RF-17 que um tipo de pendência aceita, além do motivo. */
export type TicketPendingField =
    | 'supplier_name'
    | 'protocol_number'
    | 'related_department_id'
    | 'responsible_name'
    | 'expected_return_at';

/** Tipo de pendência (CHA-08). Calculado no backend, que revalida ao gravar. */
export interface TicketPendingType {
    /** Valor técnico enviado ao servidor. */
    value: string;
    label: TicketStatus;
    description: string | null;
    /** Campos específicos aceitos por este tipo; todos opcionais. */
    fields: TicketPendingField[];
}

/** Registro de pendência (CHA-08), enviado somente a quem tem `tickets.change_status`. */
export interface TicketPendingRegistration {
    /** Valor técnico do status atual, reenviado para detectar alterações concorrentes. */
    current: string;
    /** Vazio quando o chamado não admite pendência no estado atual. */
    types: TicketPendingType[];
    /** Departamentos ativos, quando algum tipo aceita departamento relacionado. */
    departments: Option[];
    limits: { reason: number; text: number };
}

/** Pendência em andamento (RF-17), enviada somente à equipe de suporte. */
export interface TicketActivePending {
    type: TicketStatus;
    reason: string;
    supplier_name: string | null;
    protocol_number: string | null;
    related_department: string | null;
    responsible_name: string | null;
    /** AAAA-MM-DD. */
    expected_return_at: string | null;
    started_at: string;
    author: string | null;
}

/** Resolução (CHA-09), enviada somente a quem tem `tickets.resolve`. */
export interface TicketResolutionForm {
    /** Valor técnico do status atual, reenviado para detectar alterações concorrentes. */
    current: string;
    limits: { summary: number };
}

/** Solução registrada (RF-12), presente enquanto o chamado está resolvido ou encerrado. */
export interface TicketResolution {
    summary: string;
    resolved_at: string | null;
    author: string | null;
}

/** Evento de auditoria (RF-19), enviado somente a quem tem `audit.view`. */
export interface TicketHistoryItem {
    id: number;
    title: string;
    author: string | null;
    from: string | null;
    to: string | null;
    /** Motivo informado no registro de pendência. */
    reason: string | null;
    /** Resumo da solução informado na resolução. */
    summary: string | null;
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
