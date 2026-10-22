import type { Option } from '@/lib/ticket';

/** Linha da listagem de categorias (ADM-07). */
export interface CategoryListItem {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    updated_at: string | null;
    /** Só a exibição da ação de edição (ADM-09); o backend revalida. */
    can_edit: boolean;
}

/** Totais de todas as categorias, independentes da pesquisa e da página. */
export interface CategorySummary {
    total: number;
    active: number;
    inactive: number;
}

/** Categoria em edição (ADM-09), com os chamados preservados pela edição. */
export interface CategoryEditTarget {
    id: number;
    name: string;
    is_active: boolean;
    /** Chamados que referenciam a categoria; continuam vinculados a ela. */
    tickets_count: number;
    created_at: string | null;
    updated_at: string | null;
}

/** Campos do formulário de criação (ADM-08) e edição (ADM-09). */
export interface CategoryFormValues {
    name: string;
    description: string;
    status: string;
}

export type CategoryFormField = keyof CategoryFormValues;

/** Mesmas regras básicas do StoreCategoryRequest; o servidor valida tudo de novo. */
export const categoryFormErrors = (
    values: CategoryFormValues,
    limits: { name: number; description: number },
): Partial<Record<CategoryFormField, string>> => {
    const errors: Partial<Record<CategoryFormField, string>> = {};

    if (!values.name.trim()) errors.name = 'Informe o nome da categoria.';
    else if (values.name.trim().length > limits.name) errors.name = `O nome pode ter no máximo ${limits.name} caracteres.`;

    if (values.description.trim().length > limits.description)
        errors.description = `A descrição pode ter no máximo ${limits.description.toLocaleString('pt-BR')} caracteres.`;

    return errors;
};

export const categoryFilterKeys = ['q', 'status'] as const;

export type CategoryFilterKey = (typeof categoryFilterKeys)[number];
export type CategoryFilters = Partial<Record<CategoryFilterKey, string>>;

export type CategorySortKey = 'name' | 'updated_at';

export interface CategoryFilterOptions {
    statuses: Option[];
}
