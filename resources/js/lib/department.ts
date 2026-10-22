import type { Option } from '@/lib/ticket';

/** Linha da listagem de departamentos (ADM-04). */
export interface DepartmentListItem {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    /** Usuários associados ao departamento. */
    users_count: number;
    /** Chamados que referenciam o departamento (departamento histórico, RF-02). */
    tickets_count: number;
    created_at: string | null;
    updated_at: string | null;
    /** Só a exibição da ação de edição (ADM-06); o backend revalida. */
    can_edit: boolean;
}

/** Departamento em edição (ADM-06), com os vínculos preservados pela edição. */
export interface DepartmentEditTarget {
    id: number;
    name: string;
    is_active: boolean;
    users_count: number;
    active_users_count: number;
    tickets_count: number;
    created_at: string | null;
    updated_at: string | null;
}

/** Campos do formulário de criação (ADM-05) e edição (ADM-06). */
export interface DepartmentFormValues {
    name: string;
    description: string;
    status: string;
}

export type DepartmentFormField = keyof DepartmentFormValues;

/** Mesmas regras básicas do StoreDepartmentRequest; o servidor valida tudo de novo. */
export const departmentFormErrors = (
    values: DepartmentFormValues,
    limits: { name: number; description: number },
): Partial<Record<DepartmentFormField, string>> => {
    const errors: Partial<Record<DepartmentFormField, string>> = {};

    if (!values.name.trim()) errors.name = 'Informe o nome do departamento.';
    else if (values.name.trim().length > limits.name) errors.name = `O nome pode ter no máximo ${limits.name} caracteres.`;

    if (values.description.trim().length > limits.description)
        errors.description = `A descrição pode ter no máximo ${limits.description.toLocaleString('pt-BR')} caracteres.`;

    return errors;
};

export const departmentFilterKeys = ['q', 'status'] as const;

export type DepartmentFilterKey = (typeof departmentFilterKeys)[number];
export type DepartmentFilters = Partial<Record<DepartmentFilterKey, string>>;

export type DepartmentSortKey = 'name' | 'created_at' | 'updated_at';

export interface DepartmentFilterOptions {
    statuses: Option[];
}
