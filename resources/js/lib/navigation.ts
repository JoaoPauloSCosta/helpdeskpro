import type { IconName } from '@/Components/Icon.vue';

export interface NavItem {
    label: string;
    icon: IconName;
    /** Nome da rota Laravel. Sem rota (ou rota inexistente), o item não é exibido. */
    route?: string;
    /** Padrão de rota (Ziggy) que marca o item como ativo, incluindo subpáginas. */
    active?: string;
}

/**
 * Navegação principal (UI/UX Spec, seção 4.1).
 * Itens sem `route` ficam prontos aqui e aparecem sozinhos quando a rota for criada.
 * A exibição por permissão será ligada quando o projeto compartilhar permissões via Inertia;
 * a autorização real continua no backend.
 */
export const navigation: NavItem[] = [
    { label: 'Visão geral', icon: 'squares', route: 'dashboard', active: 'dashboard' },
    { label: 'Chamados', icon: 'inbox', route: 'tickets.index', active: 'tickets.*' },
    { label: 'Administração', icon: 'users' },
    { label: 'Políticas de SLA', icon: 'clock' },
    { label: 'Auditoria', icon: 'shield-check' },
    { label: 'Minha conta', icon: 'user', route: 'profile.edit', active: 'profile.*' },
];

export const isAvailable = (item: NavItem): boolean => !!item.route && route().has(item.route);
