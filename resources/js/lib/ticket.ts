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

/** Um indicador de SLA calculado no backend. `none`: o chamado não tem prazo (sem política, RF-15.3). */
export interface TicketSlaIndicator {
    state: SlaState | 'none';
    due_at: string | null;
    /** Instante em que a meta foi atingida (primeira resposta ou resolução). */
    met_at: string | null;
    /** Meta aplicada ao chamado, em minutos. */
    target_minutes: number | null;
    /** Segundos até o prazo; negativo quando vencido. Com a meta atingida, é a folga (ou o atraso) dela. */
    remaining_seconds: number | null;
    /** Primeira violação registrada (RN-15). Permanece mesmo que a situação atual volte a "No prazo". */
    breached_at: string | null;
}

/** Texto da etiqueta do indicador: "Vencido há 35 min" só enquanto o prazo está vencido e aberto (design-system 3.3). */
export const slaBadgeLabel = (sla: TicketSlaIndicator): string | undefined => {
    if (sla.remaining_seconds === null) return undefined;

    if (sla.state === 'overdue' && sla.met_at === null) return `Vencido há ${formatDuration(Math.abs(sla.remaining_seconds))}`;

    // Em risco: com 80% da meta consumida (D7), a etiqueta diz quanto falta.
    if (sla.state === 'at_risk') return `Vence em ${formatDuration(sla.remaining_seconds)}`;

    return undefined;
};

/** Linha complementar do indicador (tempo restante, folga ou atraso); vazia quando não há o que dizer. */
export const slaDetailText = (sla: TicketSlaIndicator): string | null => {
    if (sla.remaining_seconds === null) return null;

    const time = formatDuration(Math.abs(sla.remaining_seconds));

    switch (sla.state) {
        case 'on_track':
        case 'at_risk':
            return `Restam ${time}`;
        case 'paused':
            return `Restam ${time} (contagem pausada)`;
        case 'met':
            return sla.remaining_seconds === 0 ? 'Concluído no limite do prazo' : `Concluído ${time} antes do prazo`;
        case 'overdue':
            return sla.met_at !== null ? `Concluído ${time} depois do prazo` : null;
        default:
            return null;
    }
};

/** Mensagem do chamado (RF-11). Notas internas só chegam a quem pode lê-las. */
export interface TicketCommentItem {
    id: number;
    author: string | null;
    body: string;
    internal: boolean;
    created_at: string | null;
    /** Há versões anteriores que o usuário pode consultar (CHA-12). */
    edited: boolean;
}

/** Uma versão do conteúdo de um comentário (CHA-12). Somente leitura. */
export interface TicketCommentVersion {
    /** Posição na sequência de versões; 1 é o texto original. */
    number: number;
    body: string;
    internal: boolean;
    /** Quem escreveu este texto: o autor do comentário ou quem fez a edição. */
    author: string | null;
    recorded_at: string | null;
    original: boolean;
    current: boolean;
}

/** Histórico de versões de um comentário (CHA-12), da mais recente para a original. */
export interface TicketCommentHistory {
    ticket: string;
    internal: boolean;
    versions: TicketCommentVersion[];
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

/** Reabertura (CHA-10), enviada somente ao solicitante original. */
export interface TicketReopenForm {
    /** Valor técnico do status atual, reenviado para detectar alterações concorrentes. */
    current: string;
    limits: { justification: number };
}

/** Última reabertura (RF-13), presente enquanto o chamado não voltou a ser resolvido ou encerrado. */
export interface TicketReopening {
    justification: string | null;
    reopened_at: string | null;
    author: string | null;
}

/** Encerramento (CHA-11), enviado somente a quem tem `tickets.close`. */
export interface TicketCloseForm {
    /** Valor técnico do status atual, reenviado para detectar alterações concorrentes. */
    current: string;
}

/** Encerramento registrado (RF-14), presente enquanto o chamado está encerrado. */
export interface TicketClosure {
    closed_at: string | null;
    author: string | null;
}

/** Campo alterado num evento de auditoria (RF-19). */
export interface TicketAuditChange {
    field: string;
    /** Ausente: sem valor anterior aplicável (por exemplo, na abertura) · `null`: não foi gravado. */
    from?: string | null;
    /** `null`: não foi gravado. */
    to: string | null;
}

/** Informação complementar de um evento, já filtrada pelo backend; `value` é o dado gravado. */
export interface TicketAuditDetail {
    label: string;
    value: string | number;
    /** datetime: ISO 8601 · date: AAAA-MM-DD · duration: segundos · longtext: texto livre do usuário. */
    format: 'text' | 'longtext' | 'datetime' | 'date' | 'duration';
}

/** Evento de auditoria do chamado (CHA-14), montado pelo backend a partir do registro gravado. */
export interface TicketAuditEvent {
    id: number;
    type: string;
    title: string;
    tone: 'primary' | 'success' | 'warning' | 'danger' | 'neutral';
    created_at: string | null;
    /** `null`: evento sem usuário identificado. */
    author: string | null;
    description: string | null;
    changes: TicketAuditChange[];
    details: TicketAuditDetail[];
    /** Há dados gravados que o usuário não pode consultar (por exemplo, o motivo da pendência). */
    restricted: boolean;
}

/** Uma página do histórico de auditoria, em ordem cronológica (RF-19). */
export interface TicketAuditPage {
    ticket: string;
    events: TicketAuditEvent[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

/**
 * Período de pausa do SLA de resolução (RF-17), numerado em ordem cronológica. Motivo, dados da
 * pendência e autor só chegam à equipe de suporte.
 */
export interface TicketSlaPauseItem {
    number: number;
    type: TicketStatus;
    started_at: string;
    /** `null`: pausa em andamento. */
    ended_at: string | null;
    /** Gravada na retomada; `null` enquanto a pausa está em andamento. */
    duration_seconds: number | null;
    reason?: string;
    supplier_name?: string | null;
    protocol_number?: string | null;
    related_department?: string | null;
    responsible_name?: string | null;
    /** AAAA-MM-DD. */
    expected_return_at?: string | null;
    author?: string | null;
}

interface TicketSlaEventBase {
    id: number;
    created_at: string | null;
    /** Somente para a equipe de suporte. */
    author?: string | null;
}

/** Evento gravado que afeta ou documenta o SLA (CHA-13). Nada é derivado do estado atual. */
export type TicketSlaEvent = TicketSlaEventBase &
    (
        | { kind: 'pause_started'; to: TicketStatus | null }
        | {
              kind: 'resumed';
              from: TicketStatus | null;
              to: TicketStatus | null;
              duration_seconds: number | null;
              /** `false`: pausa anterior ao registro de pendências, sem período gravado. */
              pause_recorded: boolean;
              /** Prazo de resolução antes e depois da retomada; nulos sem prazo ou sem pausa registrada. */
              resolution_due_before?: string | null;
              resolution_due_after?: string | null;
          }
        | { kind: 'priority_changed'; from: TicketPriority | null; to: TicketPriority | null }
        | {
              kind: 'resolved';
              resolution_due_at: string | null;
              /** `null`: não havia prazo gravado no momento da resolução. */
              on_time: boolean | null;
              /** Tempo efetivo de resolução, sem pausas; nulo sem meta aplicada. */
              effective_seconds?: number | null;
          }
        | {
              kind: 'reopened';
              first_response_due_at: string | null;
              /** Já estendido pelo tempo em Resolvido. */
              resolution_due_at: string | null;
              resolution_due_before?: string | null;
          }
        | { kind: 'closed' }
        | {
              kind: 'sla_targets_changed';
              from: TicketPriority | null;
              to: TicketPriority | null;
              /** [antes, depois]; nulos quando o chamado não tinha política (RF-15.3). */
              first_response_minutes: [number | null, number | null];
              resolution_minutes: [number | null, number | null];
              first_response_due_at: [string | null, string | null];
              resolution_due_at: [string | null, string | null];
              consumed_seconds: number | null;
          }
        | { kind: 'sla_breached'; indicator: 'first_response' | 'resolution' | null; due_at: string | null; breached_at: string | null }
    );

/** Histórico de SLA do chamado (CHA-13), carregado sob demanda. Eventos e pausas do mais recente ao mais antigo. */
export interface TicketSlaHistory {
    ticket: string;
    /** Meta aplicada, consumo e violações registradas. Números nulos: chamado sem política ou sem prazo. */
    sla: {
        priority: TicketPriority;
        has_policy: boolean;
        first_response_minutes: number | null;
        resolution_minutes: number | null;
        consumed_seconds: number | null;
        remaining_seconds: number | null;
        /** Tempo efetivo gravado na última resolução. */
        effective_seconds: number | null;
        breaches: { first_response_at: string | null; resolution_at: string | null };
    };
    pauses: {
        count: number;
        /** Soma das pausas encerradas; `null` se nenhuma foi encerrada. */
        closed_seconds: number | null;
        items: TicketSlaPauseItem[];
    };
    events: TicketSlaEvent[];
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

/** Duração gravada pelo backend, em segundos (ex.: "1 d 2 h 5 min"). */
export const formatDuration = (seconds: number): string => {
    if (seconds < 60) return `${seconds} s`;

    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    return [days && `${days} d`, hours && `${hours} h`, minutes && `${minutes} min`].filter(Boolean).join(' ');
};

/** Converte AAAA-MM-DD em DD/MM/AAAA sem conversão de fuso. */
export const formatDate = (ymd: string): string => ymd.split('-').reverse().join('/');
