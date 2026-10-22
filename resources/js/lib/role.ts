/** Linha da listagem de papéis (PER-01). */
export interface RoleListItem {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    /** Papel da matriz inicial (PRD 7.4), criado pelo seeder. */
    is_system: boolean;
    /** Usuários vinculados ao papel, ativos e inativos. */
    users_count: number;
    permissions_count: number;
    /** Só a exibição da ação de edição (PER-03); o backend revalida. */
    can_edit: boolean;
}

/** Papel em edição (PER-03), com os vínculos preservados pela edição. */
export interface RoleEditTarget {
    id: number;
    name: string;
    slug: string;
    /** Papel da matriz inicial: o nome não pode ser alterado. */
    is_system: boolean;
    users_count: number;
    permissions_count: number;
    created_at: string | null;
    updated_at: string | null;
}

/** Totais de todos os papéis, independentes da pesquisa e da página. */
export interface RoleSummary {
    total: number;
    system: number;
    /** Usuários vinculados a algum papel. */
    users: number;
}

export const roleFilterKeys = ['q'] as const;

export type RoleFilterKey = (typeof roleFilterKeys)[number];
export type RoleFilters = Partial<Record<RoleFilterKey, string>>;

export type RoleSortKey = 'name' | 'users_count';

/** Campos do formulário de criação (PER-02) e edição (PER-03). */
export interface RoleFormValues {
    name: string;
    description: string;
}

export type RoleFormField = keyof RoleFormValues;

/** Mesmas regras básicas do StoreRoleRequest; o servidor valida tudo de novo (inclusive a unicidade). */
export const roleFormErrors = (
    values: RoleFormValues,
    limits: { name: number; description: number },
): Partial<Record<RoleFormField, string>> => {
    const errors: Partial<Record<RoleFormField, string>> = {};

    if (!values.name.trim()) errors.name = 'Informe o nome do papel.';
    else if (values.name.trim().length > limits.name) errors.name = `O nome pode ter no máximo ${limits.name} caracteres.`;

    if (values.description.trim().length > limits.description)
        errors.description = `A descrição pode ter no máximo ${limits.description.toLocaleString('pt-BR')} caracteres.`;

    return errors;
};

/* ---------- Matriz de permissões (PER-04) ---------- */

/** Papel oferecido no seletor da matriz. */
export interface RoleOption {
    id: number;
    name: string;
    is_system: boolean;
    permissions_count: number;
}

/** Por que a matriz do papel é somente leitura para o operador. */
export type RoleReadOnlyReason = 'own_role' | 'not_grantable';

/** Papel consultado na matriz, com as permissões persistidas. */
export interface RoleMatrixTarget {
    id: number;
    name: string;
    slug: string;
    is_system: boolean;
    users_count: number;
    /** Identificadores das permissões associadas. */
    permissions: string[];
    version: string;
    can_manage: boolean;
    read_only_reason: RoleReadOnlyReason | null;
}

export interface CatalogPermission {
    slug: string;
    name: string;
    description: string | null;
    administrative: boolean;
    /** Se o operador pode marcar ou desmarcar; o backend verifica de novo ao salvar. */
    grantable: boolean;
}

export interface CatalogGroup {
    key: string;
    label: string;
    permissions: CatalogPermission[];
}

export type MatrixView = 'all' | 'assigned' | 'unassigned';
