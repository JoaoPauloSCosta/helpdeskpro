import type { Option } from '@/lib/ticket';

/** Linha da listagem de usuários (ADM-01). Nunca traz senha, tokens ou outros campos sensíveis. */
export interface UserListItem {
    id: number;
    name: string;
    email: string;
    role: string | null;
    department: string | null;
    is_active: boolean;
    created_at: string | null;
    /** Só a exibição da ação de edição (ADM-03); o backend revalida. */
    can_edit: boolean;
}

export const userFilterKeys = ['q', 'role', 'department', 'status'] as const;

export type UserFilterKey = (typeof userFilterKeys)[number];
export type UserFilters = Partial<Record<UserFilterKey, string>>;

export type UserSortKey = 'name' | 'email' | 'created_at';

export interface UserFilterOptions {
    roles: Option[];
    departments: Option[];
    statuses: Option[];
}

/** Papel que o operador pode conceder na criação (ADM-02) e na edição (ADM-03). */
export interface RoleOption extends Option {
    description: string | null;
}

/** Campos do formulário de criação (ADM-02) e edição (ADM-03). */
export interface UserFormValues {
    name: string;
    email: string;
    role_id: string;
    department_id: string;
    status: string;
}

export type UserFormField = keyof UserFormValues;

/** Usuário em edição (ADM-03). Nunca traz senha, hash ou tokens. */
export interface UserEditTarget {
    id: number;
    name: string;
    email: string;
    role: string | null;
    is_active: boolean;
    is_self: boolean;
    created_at: string | null;
}

/**
 * Mesmas regras básicas do StoreUserRequest e do UpdateUserRequest; o servidor valida tudo de novo.
 * `requireRole` é falso quando o papel não pode ser alterado (a própria conta, na edição).
 */
export const userFormErrors = (
    values: UserFormValues,
    limits: { name: number; email: number },
    requireRole = true,
): Partial<Record<UserFormField, string>> => {
    const errors: Partial<Record<UserFormField, string>> = {};

    if (!values.name.trim()) errors.name = 'Informe o nome completo.';
    else if (values.name.length > limits.name) errors.name = `O nome pode ter no máximo ${limits.name} caracteres.`;

    const email = values.email.trim();
    if (!email) errors.email = 'Informe o e-mail.';
    else if (!/^[^\s@]+@[^\s@]+$/.test(email)) errors.email = 'Informe um e-mail válido.';
    else if (email.length > limits.email) errors.email = `O e-mail pode ter no máximo ${limits.email} caracteres.`;

    if (requireRole && !values.role_id) errors.role_id = 'Selecione um papel.';

    return errors;
};

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });

/** Data no fuso do navegador (ex.: 21/10/2026). */
export const formatShortDate = (iso: string | null): string => (iso ? date.format(new Date(iso)) : '—');
