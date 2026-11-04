<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ChatWidgetPanel from '@/Components/ChatWidgetPanel.vue';
import Icon from '@/Components/Icon.vue';
import type { ChatSessionState } from '@/lib/chat';
import type { PageProps } from '@/types';

/**
 * Botão flutuante do chat para quem pode pedir atendimento (`chat.request`). Fica fora das páginas do próprio
 * chat, que já mostram a conversa inteira. O painel só é montado (e só abre o tempo real) quando a pessoa o
 * abre ou já tem atendimento aberto; quem não usa o chat não paga por isso. A autorização continua no servidor.
 */
const page = usePage<PageProps>();

const OPEN_KEY = 'chat-widget-open';

const available = computed(() => !!page.props.chat && page.props.auth.permissions.includes('chat.request'));
const onChatPage = computed(() => route().current('chat.*'));
const show = computed(() => available.value && !onChatPage.value);

const ready = ref(false);
const initial = ref<ChatSessionState | null>(null);
const open = ref(sessionStorage.getItem(OPEN_KEY) === '1');
const summary = ref<{ status: string | null; unread: number; position: number | null }>({ status: null, unread: 0, position: null });
/** Há atendimento aberto: o painel continua montado, mesmo minimizado. */
const hasSession = computed(() => summary.value.status !== null);
const mounted = computed(() => ready.value && (open.value || hasSession.value || initial.value !== null));

const setOpen = (value: boolean) => {
    open.value = value;
    sessionStorage.setItem(OPEN_KEY, value ? '1' : '0');
};

onMounted(async () => {
    if (!show.value) return;

    try {
        initial.value = (await axios.get<{ session: ChatSessionState | null }>(route('chat.sessions.current'))).data.session;
    } catch {
        initial.value = null;
    } finally {
        ready.value = true;
    }
});

const badge = computed(() => {
    if (summary.value.unread > 0) return { text: String(summary.value.unread), label: `${summary.value.unread} mensagens não lidas` };
    if (summary.value.status === 'waiting' && summary.value.position) return { text: `${summary.value.position}º`, label: `${summary.value.position}º na fila` };

    return null;
});

const onSummary = (value: typeof summary.value) => {
    summary.value = value;
    // Atendimento acabou e o painel está fechado: nada a manter montado.
    if (value.status === null && !open.value) initial.value = null;
};
</script>

<template>
    <div v-if="show">
        <ChatWidgetPanel v-if="mounted" :initial="initial" :open="open" @close="setOpen(false)" @summary="onSummary" />

        <button
            type="button"
            class="fixed bottom-4 right-4 z-40 flex h-12 items-center gap-2 rounded-full bg-primary-500 px-5 text-sm font-semibold text-white shadow-lg transition-colors duration-150 hover:bg-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 active:bg-primary-700 motion-reduce:transition-none"
            :class="open && 'max-sm:hidden'"
            :aria-expanded="open"
            aria-controls="chat-widget-panel"
            @click="setOpen(!open)"
        >
            <Icon :name="open ? 'chevron-down' : 'chat'" :size="20" />
            <span class="hidden sm:inline">{{ open ? 'Minimizar' : 'Atendimento online' }}</span>
            <span v-if="!open" class="sr-only sm:hidden">Atendimento online</span>
            <span
                v-if="badge && !open"
                class="absolute -right-1 -top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-xs font-semibold text-white"
            >
                <span aria-hidden="true">{{ badge.text }}</span>
                <span class="sr-only">{{ badge.label }}</span>
            </span>
        </button>
    </div>
</template>
