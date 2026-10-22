import type { IconName } from '@/Components/Icon.vue';

/** Destino navegável, exibido só quando a rota existe e o usuário tem a permissão exigida. */
export interface NavTarget {
    label: string;
    /** Nome da rota Laravel. Sem rota (ou rota inexistente), o destino não é exibido. */
    route?: string;
    /** Padrão de rota (Ziggy) que marca o destino como ativo, incluindo subpáginas. */
    active?: string;
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
    { label: 'Políticas de SLA', icon: 'clock' },
    { label: 'Auditoria', icon: 'shield-check' },
    { label: 'Minha conta', icon: 'user', route: 'profile.edit', active: 'profile.*' },
];

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
