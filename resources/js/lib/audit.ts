import type { Option } from '@/lib/ticket';

/** Entidade afetada pelo evento, como o backend a descreve (a classe gravada nunca chega à tela). */
export interface AuditEntity {
    type: string;
    id: number;
    name: string | null;
    /** O cadastro ainda existe; o evento continua consultável mesmo quando não. */
    exists: boolean;
}

export interface AuditListItem {
    id: number;
    created_at: string | null;
    event: { value: string; label: string };
    /** Nulo em eventos sem usuário autenticado. */
    author: string | null;
    entity: AuditEntity;
}

/** Valor já formatado pelo backend: texto ou lista de textos. */
export type AuditValue = string | string[] | null;

export interface AuditChange {
    field: string;
    from: AuditValue;
    to: AuditValue;
}

export interface AuditDetail extends AuditListItem {
    changes: AuditChange[];
}

export const auditFilterKeys = ['q', 'event', 'entity', 'entity_id', 'from', 'to'] as const;

export type AuditFilterKey = (typeof auditFilterKeys)[number];
export type AuditFilters = Partial<Record<AuditFilterKey, string>>;

export interface AuditFilterOptions {
    events: Option[];
    entities: Option[];
}

const dateTimeUtc = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'medium', timeZone: 'UTC' });

/**
 * Data e hora em UTC (ex.: 21/10/2026 09:12:05), o mesmo fuso em que o período é filtrado, para que a
 * data exibida e a data pesquisada sempre coincidam.
 */
export const formatAuditDateTime = (iso: string | null): string => (iso ? dateTimeUtc.format(new Date(iso)) : '—');

/** Data de um filtro (AAAA-MM-DD) como dd/mm/aaaa. */
export const formatFilterDate = (ymd: string): string => ymd.split('-').reverse().join('/');
