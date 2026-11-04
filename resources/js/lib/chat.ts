import type { Tone } from '@/lib/ui';

/** Estado de uma sessão, como o servidor o monta (App\Support\ChatSessionState). */
export interface ChatSessionState {
    id: number;
    subject: string;
    status: { value: 'waiting' | 'active' | 'cancelled' | 'ended'; label: string };
    queue: { position: number; ahead: number } | null;
    no_technician_on_duty: boolean;
    technician: { name: string } | null;
    /** Nulo para o próprio solicitante. */
    requester: { name: string } | null;
    outcome: { value: 'resolved' | 'unresolved'; label: string } | null;
    ticket_number: string | null;
    created_at: string;
    accepted_at: string | null;
    cancelled_at: string | null;
    ended_at: string | null;
}

export interface ChatMessage {
    id: number;
    session_id: number;
    author: { id: number; name: string };
    body: string;
    client_id: string;
    created_at: string;
}

/** Mensagem ainda não confirmada pelo servidor; reenviada com o mesmo `client_id`, sem duplicar. */
export interface PendingMessage {
    client_id: string;
    body: string;
    created_at: string;
    status: 'sending' | 'failed';
    error?: string;
}

export interface AgentState {
    is_available: boolean;
    availability: { value: 'available' | 'busy' | 'unavailable'; label: string };
    active_sessions: number;
    max_sessions: number;
}

export interface QueueEntry {
    position: number;
    subject: string;
    department: string;
    waiting_since: string;
}

export interface ConsoleSession extends ChatSessionState {
    unread: number;
}

export interface ConsoleState {
    agent: AgentState | null;
    queue: { waiting: number; entries: QueueEntry[] } | null;
    sessions: ConsoleSession[];
}

/** Dados do modal de encerramento (RF-34); só para quem tem `chat.close`. */
export interface ChatClosureForm {
    categories: { value: string; label: string }[];
    limits: { title: number; summary: number; note: number };
}

/** Resumo do atendimento que gerou o chamado; a conversa é lida sob demanda. */
export interface TicketChatSummary {
    technician: string | null;
    started_at: string;
    ended_at: string | null;
    messages: number;
}

export interface ChatConfig {
    pollInterval: number;
    heartbeatInterval: number;
    messageMaxLength: number;
    subjectMaxLength: number;
}

export const availabilityTone: Record<AgentState['availability']['value'], Tone> = {
    available: 'success',
    busy: 'warning',
    unavailable: 'neutral',
};

const ORDINALS = ['primeiro', 'segundo', 'terceiro', 'quarto', 'quinto', 'sexto', 'sétimo', 'oitavo', 'nono', 'décimo'];

/** Texto da posição individual (CHAT-02). Sem estimativa de espera: não há regra de cálculo aprovada. */
export const positionMessage = (position: number): string => {
    if (position <= 1) return 'Você é o primeiro da fila e será atendido em breve.';

    const ahead = position - 1;
    const ordinal = ORDINALS[position - 1] ?? `${position}º`;

    return `Você é o ${ordinal} da fila. ${ahead === 1 ? 'Existe 1 solicitante aguardando' : `Existem ${ahead} solicitantes aguardando`} antes de você.`;
};

export const uuid = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
              const r = (Math.random() * 16) | 0;
              return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
          });

export const formatTime = (iso: string): string =>
    new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

/** Tempo decorrido legível (ex.: "3 min", "1 h 05 min"). */
export const elapsed = (iso: string, now = Date.now()): string => {
    const minutes = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000));

    if (minutes < 1) return 'menos de 1 min';
    if (minutes < 60) return `${minutes} min`;

    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
};
