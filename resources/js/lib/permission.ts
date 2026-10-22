import type { Option } from '@/lib/ticket';

/** Linha do catálogo de permissões (PER-05). */
export interface PermissionListItem {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    /** Módulo, derivado do prefixo do identificador. */
    group: string;
    group_label: string;
    administrative: boolean;
    /** Papéis que recebem a permissão hoje. */
    roles_count: number;
}

/** Permissão aberta no painel de detalhes. */
export interface PermissionDetail extends Omit<PermissionListItem, 'roles_count'> {
    roles: { id: number; name: string; is_system: boolean }[];
}

/** Totais do catálogo inteiro, independentes da pesquisa e da página. */
export interface PermissionSummary {
    total: number;
    modules: number;
    administrative: number;
}

export const permissionFilterKeys = ['q', 'module'] as const;

export type PermissionFilterKey = (typeof permissionFilterKeys)[number];
export type PermissionFilters = Partial<Record<PermissionFilterKey, string>>;

export type PermissionSortKey = 'name' | 'slug';

export interface PermissionFilterOptions {
    /** Só os módulos presentes no catálogo. */
    modules: Option[];
}
