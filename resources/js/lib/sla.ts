import { formatDuration, type Option, type TicketPriority } from '@/lib/ticket';

/** Linha da listagem de políticas de SLA (SLA-01). Metas em minutos corridos (RF-15). */
export interface SlaPolicyListItem {
    id: number;
    priority: TicketPriority;
    first_response_minutes: number;
    resolution_minutes: number;
    is_active: boolean;
    updated_at: string | null;
    /** Só a exibição da ação de detalhes (SLA-04); a tela de destino revalida. */
    can_view: boolean;
    /** Só a exibição da ação de edição (SLA-03); o backend revalida. */
    can_edit: boolean;
}

/** Política em edição (SLA-03), com os valores atuais confirmados pelo servidor. */
export interface SlaPolicyEditTarget {
    id: number;
    priority: TicketPriority;
    is_active: boolean;
    first_response_minutes: number;
    resolution_minutes: number;
    /** Outra política ativa na mesma prioridade: esta não pode ser ativada. */
    other_active: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export type SlaPolicyEditValues = Omit<SlaPolicyFormValues, 'priority'>;

/** Política consultada nos detalhes (SLA-04). */
export interface SlaPolicyDetail {
    id: number;
    priority: TicketPriority;
    is_active: boolean;
    first_response_minutes: number;
    resolution_minutes: number;
    created_at: string | null;
    updated_at: string | null;
}

/** Política ativa da mesma prioridade, quando a consultada está inativa. */
export interface SlaPolicySibling {
    id: number;
    first_response_minutes: number;
    resolution_minutes: number;
}

export type SlaPolicyHistoryField = 'first_response_minutes' | 'resolution_minutes' | 'is_active';

/** Evento de auditoria da política (RF-19), exibido só para quem tem `audit.view`. */
export interface SlaPolicyHistoryEvent {
    id: number;
    event: 'sla_policy_created' | 'sla_policy_updated' | 'sla_policy_deactivated' | 'sla_policy_activated';
    title: string;
    author: string | null;
    created_at: string | null;
    changes: { field: SlaPolicyHistoryField; from: number | boolean | null; to: number | boolean }[];
}

/** Totais de todas as políticas, independentes dos filtros e da página. */
export interface SlaPolicySummary {
    total: number;
    active: number;
    inactive: number;
    /** Prioridades existentes e quantas têm ao menos uma política ativa. */
    priorities: number;
    covered: number;
}

export const slaPolicyFilterKeys = ['priority', 'status'] as const;

export type SlaPolicyFilterKey = (typeof slaPolicyFilterKeys)[number];
export type SlaPolicyFilters = Partial<Record<SlaPolicyFilterKey, string>>;

export type SlaPolicySortKey = 'priority' | 'first_response_minutes' | 'resolution_minutes' | 'updated_at';

export interface SlaPolicyFilterOptions {
    priorities: Option[];
    statuses: Option[];
}

/** Meta gravada em minutos (ex.: 90 → "1 h 30 min"; 1.500 → "1 d 1 h"). */
export const formatTarget = (minutes: number): string => formatDuration(minutes * 60);

/** Prioridade oferecida no cadastro (SLA-02), com a indicação de política ativa existente. */
export interface SlaPriorityOption extends Option {
    value: string;
    label: TicketPriority;
    has_active: boolean;
}

/** Campos do formulário de criação (SLA-02). Metas digitadas em minutos, como texto. */
export interface SlaPolicyFormValues {
    priority: string;
    first_response_minutes: string;
    resolution_minutes: string;
    status: string;
}

export type SlaPolicyFormField = keyof SlaPolicyFormValues;

export type TargetLimits = { min: number; max: number };

/** Minutos inteiros dentro dos limites; nulo quando o texto não é uma meta válida. */
export const parseTarget = (value: string, limits: TargetLimits): number | null => {
    const text = value.trim();
    if (!/^\d+$/.test(text)) return null;

    const minutes = Number(text);

    return minutes >= limits.min && minutes <= limits.max ? minutes : null;
};

/**
 * Mesmas regras básicas do StoreSlaPolicyRequest; o servidor valida tudo de novo. Na edição (SLA-03)
 * não há prioridade, que não é editável.
 */
export const slaPolicyFormErrors = (
    values: Omit<SlaPolicyFormValues, 'priority'> & { priority?: string },
    limits: TargetLimits,
): Partial<Record<SlaPolicyFormField, string>> => {
    const errors: Partial<Record<SlaPolicyFormField, string>> = {};
    const range = `entre ${limits.min.toLocaleString('pt-BR')} e ${limits.max.toLocaleString('pt-BR')} minutos`;

    const target = (field: 'first_response_minutes' | 'resolution_minutes', name: string) => {
        const text = values[field].trim();
        if (!text) errors[field] = `Informe a meta de ${name}.`;
        else if (!/^\d+$/.test(text)) errors[field] = `Informe a meta de ${name} em minutos inteiros.`;
        else if (parseTarget(text, limits) === null) errors[field] = `A meta de ${name} deve estar ${range}.`;
    };

    if (values.priority !== undefined && !values.priority) errors.priority = 'Selecione a prioridade.';
    target('first_response_minutes', 'primeira resposta');
    target('resolution_minutes', 'resolução');
    if (!values.status) errors.status = 'Selecione a situação inicial.';

    return errors;
};
