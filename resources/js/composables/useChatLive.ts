import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import type { ChatMessage } from '@/lib/chat';

export interface SessionEventPayload {
    session_id: number;
    type: 'requested' | 'cancelled' | 'accepted' | 'transferred' | 'ended';
    status: 'waiting' | 'active' | 'cancelled' | 'ended';
    technician: { name: string } | null;
    ticket_number: string | null;
}

export interface PositionPayload {
    session_id: number;
    position: number;
    ahead: number;
}

interface Handlers {
    onMessage?: (message: ChatMessage) => void;
    onSession?: (event: SessionEventPayload) => void;
    onPosition?: (event: PositionPayload) => void;
    /** Total aguardando; só chega a quem pode ver a fila. */
    onQueue?: (event: { waiting: number }) => void;
}

/**
 * Tempo real do chat (docs/chat/06-tempo-real.md). Assina o canal privado do próprio usuário (e o da fila,
 * se pedido) e cuida da recuperação: ao reconectar, ao voltar a aba para o primeiro plano e, sem conexão,
 * a cada `pollInterval` segundos, chama `resync`. O banco é a fonte da verdade: o aviso em tempo real só
 * acelera a tela, e perder um aviso nunca perde uma mensagem.
 */
export function useChatLive(
    userId: number,
    handlers: Handlers,
    resync: () => void | Promise<void>,
    options: { pollInterval: number; queue?: boolean },
) {
    const status = useConnectionStatus();
    const connected = computed(() => status.value === 'connected');

    const channel = `chat.user.${userId}`;
    useEcho<'.chat.message'>(channel, '.chat.message', (payload) => handlers.onMessage?.(payload as unknown as ChatMessage));
    useEcho<'.chat.session'>(channel, '.chat.session', (payload) => handlers.onSession?.(payload as unknown as SessionEventPayload));
    useEcho<'.chat.queue.position'>(channel, '.chat.queue.position', (payload) => handlers.onPosition?.(payload as unknown as PositionPayload));

    if (options.queue) {
        useEcho<'.chat.queue'>('chat.queue', '.chat.queue', (payload) => handlers.onQueue?.(payload as unknown as { waiting: number }));
    }

    let timer: ReturnType<typeof setInterval> | undefined;

    const stopPolling = () => {
        clearInterval(timer);
        timer = undefined;
    };

    // Reconectou: busca o que perdeu. Desconectado: consulta periodicamente até voltar.
    watch(
        status,
        (current, previous) => {
            if (current === 'connected') {
                stopPolling();
                if (previous !== undefined) void resync();

                return;
            }

            timer ??= setInterval(() => void resync(), options.pollInterval * 1000);
        },
        { immediate: true },
    );

    const onVisible = () => {
        if (document.visibilityState === 'visible') void resync();
    };

    onMounted(() => document.addEventListener('visibilitychange', onVisible));
    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisible);
        stopPolling();
    });

    return { status, connected };
}
