import axios, { isAxiosError } from 'axios';
import { computed, ref, type Ref } from 'vue';
import { uuid, type ChatMessage, type PendingMessage } from '@/lib/chat';

/**
 * Conversa de uma sessão (RF-30, RF-33). As mensagens confirmadas vêm do servidor e são unidas pelo `id`, então
 * o que chega em tempo real e o que é buscado ao reconectar nunca duplicam. O envio é por HTTP com um `client_id`:
 * em falha de rede a mensagem fica "Não enviada" e "Tentar novamente" reutiliza o mesmo `client_id`, de modo que
 * o servidor não grava duas vezes.
 */
export function useChatConversation(sessionId: Ref<number | null>, currentUserId: number) {
    const confirmed = ref<ChatMessage[]>([]);
    const pending = ref<PendingMessage[]>([]);
    const loading = ref(false);
    /** O servidor recusou o envio porque o atendimento não está mais em andamento. */
    const closed = ref(false);

    const lastId = computed(() => confirmed.value.at(-1)?.id ?? 0);

    /** Confirmadas e ainda não confirmadas, em ordem. A pendente some assim que a confirmada chega. */
    const messages = computed(() => [
        ...confirmed.value.map((message) => ({ ...message, status: 'sent' as const })),
        ...pending.value.filter((item) => !confirmed.value.some((message) => message.client_id === item.client_id)),
    ]);

    const reset = () => {
        confirmed.value = [];
        pending.value = [];
        closed.value = false;
    };

    /** Guarda a mensagem (do tempo real ou do servidor) sem duplicar e sem desordenar. */
    const receive = (message: ChatMessage) => {
        if (message.session_id !== sessionId.value) return false;
        if (confirmed.value.some((item) => item.id === message.id)) return false;

        confirmed.value = [...confirmed.value, message].sort((a, b) => a.id - b.id);
        pending.value = pending.value.filter((item) => item.client_id !== message.client_id);

        return true;
    };

    /** Busca o que falta depois da última mensagem conhecida (abertura, reconexão, volta da aba). */
    const load = async (): Promise<ChatMessage[]> => {
        const id = sessionId.value;
        if (id === null) return [];

        const added: ChatMessage[] = [];
        loading.value = true;

        try {
            let more = true;

            while (more && sessionId.value === id) {
                const { data } = await axios.get<{ messages: ChatMessage[]; has_more: boolean }>(
                    route('chat.sessions.messages.index', { session: id }),
                    { params: { after_id: lastId.value } },
                );

                data.messages.forEach((message) => receive(message) && added.push(message));
                more = data.has_more && data.messages.length > 0;
            }
        } finally {
            loading.value = false;
        }

        return added;
    };

    const post = async (item: PendingMessage) => {
        const id = sessionId.value;
        if (id === null) return;

        item.status = 'sending';
        item.error = undefined;

        try {
            const { data } = await axios.post<ChatMessage>(route('chat.sessions.messages.store', { session: id }), {
                body: item.body,
                client_id: item.client_id,
            });

            receive(data);
        } catch (error) {
            item.status = 'failed';

            if (isAxiosError(error) && error.response) {
                const { status, data } = error.response;
                // 409: o atendimento terminou. 422: texto inválido. Outros 4xx/5xx: pode tentar de novo.
                item.error =
                    data?.message ?? data?.errors?.body?.[0] ?? data?.errors?.client_id?.[0] ?? 'Não foi possível enviar. Tente novamente.';
                if (status === 409) closed.value = true;
            } else {
                item.error = 'Sem conexão. A mensagem não foi enviada.';
            }
        }
    };

    const send = async (body: string) => {
        const text = body.trim();
        if (!text) return;

        pending.value.push({ client_id: uuid(), body: text, created_at: new Date().toISOString(), status: 'sending' });

        // O item reativo (e não o objeto original) é o que `post` altera, para a tela acompanhar.
        await post(pending.value[pending.value.length - 1]);
    };

    const retry = async (clientId: string) => {
        const item = pending.value.find((entry) => entry.client_id === clientId);
        if (item && item.status === 'failed') await post(item);
    };

    /** Descarta uma mensagem que falhou e que a pessoa não quer reenviar. */
    const discard = (clientId: string) => {
        pending.value = pending.value.filter((entry) => entry.client_id !== clientId);
    };

    /** Última mensagem de outra pessoa, para a marcação de lida. */
    const lastIncomingId = computed(() => confirmed.value.filter((message) => message.author.id !== currentUserId).at(-1)?.id ?? 0);

    let readTimer: ReturnType<typeof setTimeout> | undefined;
    let lastRead = 0;

    /** Avisa o servidor, sem pressa e sem repetir, que as mensagens até aqui foram vistas. */
    const markRead = () => {
        const id = sessionId.value;
        const upTo = lastIncomingId.value;
        if (id === null || upTo <= lastRead) return;

        clearTimeout(readTimer);
        readTimer = setTimeout(async () => {
            lastRead = upTo;
            await axios.post(route('chat.sessions.read', { session: id }), { last_id: upTo }).catch(() => (lastRead = 0));
        }, 400);
    };

    return { messages, loading, closed, lastId, reset, receive, load, send, retry, discard, markRead };
}
