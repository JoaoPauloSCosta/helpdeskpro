import axios, { isAxiosError } from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useChatConversation } from '@/composables/useChatConversation';
import { useChatLive, type PositionPayload, type SessionEventPayload } from '@/composables/useChatLive';
import { useChatSound } from '@/composables/useChatSound';
import { useToast } from '@/composables/useToast';
import type { ChatMessage, ChatSessionState } from '@/lib/chat';
import type { PageProps } from '@/types';

/**
 * Atendimento do solicitante (CHAT-01 a 03): pedido, fila, conversa e cancelamento. Usado pela página
 * `/chat` e pelo botão flutuante, para o comportamento ser um só. O servidor é a fonte da verdade: cada
 * aviso em tempo real só pede para reler o estado.
 *
 * `visible` diz se a conversa está à vista (página aberta ou painel aberto). Fora da vista, as mensagens
 * novas contam como não lidas e avisam por som e toast, em vez de serem marcadas como lidas.
 */
export function useRequesterChat(initial: ChatSessionState | null, visible: Ref<boolean> = ref(true)) {
    const page = usePage<PageProps>();
    const config = page.props.chat!;
    const userId = page.props.auth.user.id;
    const toast = useToast();
    const sound = useChatSound();

    const session = ref<ChatSessionState | null>(initial);
    const subject = ref('');
    const errors = ref<Record<string, string>>({});
    const requesting = ref(false);
    const cancelling = ref(false);
    const confirmCancel = ref(false);
    /** Mensagem da resposta "sem técnico de plantão" (RN-CHAT-06); leva à abertura de chamado tradicional. */
    const unavailable = ref<string | null>(null);
    const unread = ref(0);

    const status = computed(() => session.value?.status.value ?? null);
    /** A conversa continua legível depois do encerramento. */
    const conversationId = computed(() => (status.value === 'active' || status.value === 'ended' ? session.value!.id : null));
    const conversation = useChatConversation(conversationId, userId);
    const closed = computed(() => status.value === 'ended' || conversation.closed.value);

    /** À vista e com a aba em primeiro plano: pode marcar como lida. */
    const seen = () => visible.value && document.visibilityState === 'visible';

    const loadConversation = async () => {
        const added = await conversation.load();
        const fromOthers = added.filter((m) => m.author.id !== userId).length;

        if (fromOthers) {
            void sound.notify();
            if (!visible.value) unread.value += fromOthers;
        }
        if (seen()) conversation.markRead();
    };

    /** Fonte da verdade: relê a sessão e as mensagens que faltam. Usada ao reconectar e na consulta periódica. */
    const refresh = async () => {
        if (!session.value) return;

        try {
            const { data } = await axios.get<ChatSessionState>(route('chat.sessions.show', { session: session.value.id }));
            // Cancelada: volta ao pedido. A leitura pode chegar depois da resposta do cancelamento.
            session.value = data.status.value === 'cancelled' ? null : data;
            if (conversationId.value !== null) await loadConversation();
        } catch (error) {
            if (isAxiosError(error) && error.response?.status === 404) session.value = null;
        }
    };

    watch(conversationId, (id, previous) => {
        if (id !== previous) conversation.reset();
        if (id !== null) void loadConversation();
    }, { immediate: true });

    watch(visible, (now) => {
        if (!now) return;
        unread.value = 0;
        if (conversationId.value !== null) conversation.markRead();
    });

    const live = useChatLive(
        userId,
        {
            onMessage: (message: ChatMessage) => {
                if (!conversation.receive(message)) return;
                if (message.author.id === userId) return;

                void sound.notify();
                if (seen()) return conversation.markRead();

                if (!visible.value) unread.value += 1;
                toast.info(`Nova mensagem de ${message.author.name}.`);
            },
            onSession: (event: SessionEventPayload) => {
                if (event.session_id !== session.value?.id) return;
                if (event.type === 'accepted') {
                    void sound.notify();
                    toast.success(`Sua vez chegou. ${event.technician?.name ?? 'Um técnico'} iniciou o atendimento.`);
                }
                if (event.type === 'ended') toast.info(event.ticket_number ? `Atendimento encerrado. Chamado ${event.ticket_number}.` : 'Atendimento encerrado.');
                void refresh();
            },
            onPosition: (event: PositionPayload) => {
                if (session.value?.status.value === 'waiting' && event.session_id === session.value.id) {
                    session.value = { ...session.value, queue: { position: event.position, ahead: event.ahead } };
                }
            },
        },
        refresh,
        { pollInterval: config.pollInterval },
    );

    // Enquanto aguarda, confere de tempos em tempos se ainda há técnico de plantão (sem aviso em tempo real para isso).
    let waitingTimer: ReturnType<typeof setInterval> | undefined;
    onMounted(() => (waitingTimer = setInterval(() => status.value === 'waiting' && void refresh(), 30000)));
    onBeforeUnmount(() => clearInterval(waitingTimer));

    const requestChat = async () => {
        errors.value = {};
        unavailable.value = null;
        requesting.value = true;

        try {
            const { data } = await axios.post<ChatSessionState>(route('chat.sessions.store'), { subject: subject.value });
            session.value = data;
            subject.value = '';
        } catch (error) {
            if (!isAxiosError(error) || !error.response) return void toast.unexpected();

            const { status: code, data } = error.response;

            if (code === 409 && data?.code === 'no_technician_available') unavailable.value = data.message;
            else if (code === 422) {
                errors.value = Object.fromEntries(Object.entries<string[]>(data.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
                toast.formErrors();
            } else if (code === 429) toast.warning('Muitas tentativas seguidas. Aguarde um instante e tente de novo.');
            else toast.unexpected();
        } finally {
            requesting.value = false;
        }
    };

    const cancel = async () => {
        if (!session.value) return;
        cancelling.value = true;

        try {
            await axios.post(route('chat.sessions.cancellation.store', { session: session.value.id }));
            session.value = null;
            toast.success('Solicitação cancelada.');
        } catch (error) {
            if (isAxiosError(error) && error.response?.status === 409) {
                toast.info('O atendimento já foi iniciado e não pode mais ser cancelado.');
                await refresh();
            } else toast.unexpected();
        } finally {
            cancelling.value = false;
            confirmCancel.value = false;
        }
    };

    const newRequest = () => {
        session.value = null;
        conversation.reset();
    };

    return {
        config, userId, sound, live, session, subject, errors, requesting, cancelling, confirmCancel, unavailable,
        unread, status, conversation, closed, requestChat, cancel, newRequest, refresh,
    };
}
