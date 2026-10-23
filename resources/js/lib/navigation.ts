import type { IconName } from '@/Components/Icon.vue';

/** Destino navegável, exibido só quando a rota existe e o usuário tem a permissão exigida. */
export interface NavTarget {
    label: string;
    /** Nome da rota Laravel. Sem rota (ou rota inexistente), o destino não é exibido. */
    route?: string;
    /** Padrões de rota (Ziggy) que marcam o destino como ativo, incluindo subpáginas. */
    active?: string | string[];
    /** Permissão exigida para exibir o destino. A autorização real continua no backend. */
    permission?: string;
}

export interface NavItem extends NavTarget {
    icon: IconName;
    /** Seções internas; sem `route` próprio, o item leva à primeira seção disponível. */
    sections?: NavTarget[];
}

/** Seções da Administração (UI/UX Spec, seção 3.3), cada uma com a sua permissão (PRD 7.3). */
export const adminSections: NavTarget[] = [
    { label: 'Usuários', route: 'admin.users.index', active: 'admin.users.*', permission: 'users.manage' },
    { label: 'Departamentos', route: 'admin.departments.index', active: 'admin.departments.*', permission: 'departments.manage' },
    { label: 'Categorias', route: 'admin.categories.index', active: 'admin.categories.*', permission: 'categories.manage' },
    {
        label: 'Papéis e permissões',
        route: 'admin.roles.index',
        // Inclui o catálogo de permissões (PER-05), que pertence à mesma seção.
        active: ['admin.roles.*', 'admin.permissions.*'],
        permission: 'roles.manage',
    },
];

/**
 * Navegação principal (UI/UX Spec, seção 4.1).
 * Itens sem `route` ficam prontos aqui e aparecem sozinhos quando a rota for criada.
 * Itens com `permission` só aparecem para quem a possui (permissões efetivas compartilhadas
 * pelo Inertia); a autorização real continua no backend.
 */
export const navigation: NavItem[] = [
    { label: 'Visão geral', icon: 'squares', route: 'dashboard', active: 'dashboard' },
    { label: 'Chamados', icon: 'inbox', route: 'tickets.index', active: 'tickets.*' },
    { label: 'Administração', icon: 'users', active: 'admin.*', sections: adminSections },
    { label: 'Políticas de SLA', icon: 'clock', route: 'sla-policies.index', active: 'sla-policies.*', permission: 'sla.manage' },
    { label: 'Auditoria', icon: 'shield-check', route: 'audit.index', active: 'audit.*', permission: 'audit.view' },
    { label: 'Minha conta', icon: 'user', route: 'profile.edit', active: 'profile.*' },
];

/** Se a página atual corresponde a algum dos padrões do destino. */
export const isCurrent = (target: NavTarget, fallback: string): boolean =>
    ([] as string[]).concat(target.active ?? fallback).some((pattern) => route().current(pattern));

export const isAvailable = (target: NavTarget, permissions: string[]): boolean =>
    !!target.route && route().has(target.route) && (!target.permission || permissions.includes(target.permission));

/** Página inicial da Administração para o usuário: a primeira seção disponível. */
export const adminHomeHref = (permissions: string[]): string | null => {
    const section = adminSections.find((target) => isAvailable(target, permissions));

    return section ? route(section.route!) : null;
};

/** Rota de destino do item:a própria ou a da primeira seção disponível; nula se não houver. */
export const resolveRoute = (item: NavItem, permissions: string[]): string | null => {
    if (item.sections) return item.sections.find((section) => isAvailable(section, permissions))?.route ?? null;

    return isAvailable(item, permissions) ? item.route! : null;
};
