import type { Tone } from '@/lib/ui';

/** Os oito status do RF-10. O texto do badge é sempre exibido junto da cor. */
export const ticketStatuses = [
    'Aberto',
    'Em atendimento',
    'Aguardando solicitante',
    'Pendente',
    'Pendente Terceiros',
    'Pendente Departamentos',
    'Resolvido',
    'Encerrado',
] as const;

export type TicketStatus = (typeof ticketStatuses)[number];

export const statusTone: Record<TicketStatus, Tone> = {
    Aberto: 'info',
    'Em atendimento': 'primary',
    'Aguardando solicitante': 'warning',
    Pendente: 'warning',
    'Pendente Terceiros': 'warning',
    'Pendente Departamentos': 'warning',
    Resolvido: 'success',
    Encerrado: 'neutral',
};

/** Status que pausam o SLA de resolução (RF-15.2). */
export const pausedStatuses: TicketStatus[] = [
    'Aguardando solicitante',
    'Pendente',
    'Pendente Terceiros',
    'Pendente Departamentos',
];

/** Nomes ainda não confirmados no PRD (design-system.md, seção 3.2). */
export const ticketPriorities = ['Baixa', 'Média', 'Alta', 'Crítica'] as const;

export type TicketPriority = (typeof ticketPriorities)[number];

export const priorityTone: Record<TicketPriority, Tone> = {
    Baixa: 'neutral',
    Média: 'info',
    Alta: 'warning',
    Crítica: 'danger',
};

export const priorityGlyph: Record<TicketPriority, string> = {
    Baixa: '▽',
    Média: '◇',
    Alta: '△',
    Crítica: '▲',
};

export type SlaState = 'on_track' | 'at_risk' | 'overdue' | 'paused' | 'met';
