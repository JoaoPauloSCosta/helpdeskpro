<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ChatAvailabilityBadge from '@/Components/ChatAvailabilityBadge.vue';
import ChatConnectionStatus from '@/Components/ChatConnectionStatus.vue';
import ChatConversation from '@/Components/ChatConversation.vue';
import ChatSoundToggle from '@/Components/ChatSoundToggle.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Switch from '@/Components/Switch.vue';
import { useChatConversation } from '@/composables/useChatConversation';
import { useChatLive, type SessionEventPayload } from '@/composables/useChatLive';
import { useChatSound } from '@/composables/useChatSound';
import { useToast } from '@/composables/useToast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CloseChatModal from '@/Pages/Chat/Partials/CloseChatModal.vue';
import { elapsed, formatTime, type AgentState, type ChatClosureForm, type ChatMessage, type ConsoleState } from '@/lib/chat';
import type { PageProps } from '@/types';

const props = defineProps<{ console: ConsoleState; closure: ChatClosureForm | null }>();

const page = usePage<PageProps>();
const config = page.props.chat!;
const userId = page.props.auth.user.id;
const toast = useToast();
const sound = useChatSound();

// Só controla o que aparece; cada operação é autorizada de novo no servidor.
const can = (slug: string) => page.props.auth.permissions.includes(slug);

const state = ref<ConsoleState>(props.console);
const selectedId = ref<number | null>(state.value.sessions[0]?.id ?? null);
const accepting = ref(false);
const togglingAvailability = ref(false);
const now = ref(Date.now());
const closing = ref(false);

const selected = computed(() => state.value.sessions.find((session) => session.id === selectedId.value) ?? null);
const conversation = useChatConversation(selectedId, userId);
/** Sessão do modal de encerramento: mantida depois de sair da lista, para o modal mostrar o resultado. */
const closingSession = ref<{ id: number; subject: string } | null>(null);
watch(closing, (open) => {
    if (open && selected.value) closingSession.value = { id: selected.value.id, subject: selected.value.subject };
});

/** "Atender próximo" só faz sentido com fila, permissão e vaga; o servidor decide de verdade. */
const canAccept = computed(
    () => can('chat.accept_next') && (state.value.queue?.waiting ?? 0) > 0 && state.value.agent?.availability.value === 'available',
);

const acceptHint = computed(() => {
    const agent = state.value.agent;
    if (!agent) return null;
    if (agent.availability.value === 'unavailable') return 'Ative a sua disponibilidade para atender.';
    if (agent.availability.value === 'busy') return 'Você atingiu o limite de atendimentos simultâneos.';
    if (!(state.value.queue?.waiting ?? 0)) return 'Não há solicitantes aguardando.';

    return null;
});

/* ---------- Estado do servidor ---------- */

const refresh = async () => {
    try {
        const { data } = await axios.get<ConsoleState>(route('chat.console.state'));
        const previousTotal = state.value.queue?.waiting ?? 0;

        state.value = data;

        // Acompanhar a nova solicitação mesmo se o aviso em tempo real se perdeu.
        if ((data.queue?.waiting ?? 0) > previousTotal) announceRequest();
    } catch {
        return;
    }

    if (selectedId.value !== null && !selected.value) {
        selectedId.value = null;
        toast.info('O atendimento selecionado foi encerrado ou transferido.');
    }
    if (selectedId.value === null && state.value.sessions.length === 1) selectedId.value = state.value.sessions[0].id;
    if (selectedId.value !== null) await loadConversation();
};

const announceRequest = () => {
    void sound.notify();
    toast.info('Nova solicitação na fila.');
};

const loadConversation = async () => {
    const added = await conversation.load();
    if (added.some((m) => m.author.id !== userId)) void sound.notify();
    markSeen();
};

/** Zera o contador de não lidas da conversa aberta e avisa o servidor. */
const markSeen = () => {
    const open = selected.value;
    if (open && open.unread > 0) open.unread = 0;
    conversation.markRead();
};

watch(selectedId, (id, previous) => {
    if (id === previous) return;
    conversation.reset();
    if (id !== null) void loadConversation();
}, { immediate: true });

let refreshTimer: ReturnType<typeof setTimeout> | undefined;
/** Vários avisos juntos viram uma só consulta. */
const refreshSoon = () => {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => void refresh(), 250);
};

const live = useChatLive(
    userId,
    {
        onMessage: (message: ChatMessage) => {
            if (message.session_id === selectedId.value) {
                if (!conversation.receive(message)) return;
                if (message.author.id !== userId) {
                    void sound.notify();
                    if (document.visibilityState === 'visible') markSeen();
                }

                return;
            }
            // Outra conversa: o contador de não lidas vem do servidor.
            if (message.author.id !== userId) {
                void sound.notify();
                toast.info(`Nova mensagem de ${message.author.name}.`);
            }
            refreshSoon();
        },
        onSession: (event: SessionEventPayload) => {
            if (event.type === 'accepted' && !selectedId.value) selectedId.value = event.session_id;
            refreshSoon();
        },
        onQueue: (event) => {
            if (event.waiting > (state.value.queue?.waiting ?? 0)) announceRequest();
            refreshSoon();
        },
    },
    refresh,
    { pollInterval: config.pollInterval, queue: can('chat.view_queue') },
);

/* ---------- Presença (D-C3) e relógio ---------- */

let heartbeat: ReturnType<typeof setInterval> | undefined;
let clock: ReturnType<typeof setInterval> | undefined;

const sendHeartbeat = () => {
    if (state.value.agent) void axios.post(route('chat.agent.heartbeat')).catch(() => undefined);
};

onMounted(() => {
    heartbeat = setInterval(sendHeartbeat, config.heartbeatInterval * 1000);
    clock = setInterval(() => (now.value = Date.now()), 30000);
});
onBeforeUnmount(() => {
    clearInterval(heartbeat);
    clearInterval(clock);
    clearTimeout(refreshTimer);
});

/* ---------- Ações ---------- */

const setAvailability = async (available: boolean) => {
    togglingAvailability.value = true;

    try {
        const { data } = await axios.put<AgentState>(route('chat.agent.availability.update'), { available });
        state.value = { ...state.value, agent: data };
    } catch {
        toast.unexpected();
    } finally {
        togglingAvailability.value = false;
    }
};

/** O atendimento foi encerrado: sai da lista e a seleção é limpa. O modal segue aberto mostrando o chamado gerado. */
const onEnded = async () => {
    selectedId.value = null;
    await refresh();
};

const acceptNext = async () => {
    accepting.value = true;

    try {
        const { data } = await axios.post<{ id: number }>(route('chat.queue.next'));
        selectedId.value = data.id;
        await refresh();
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 409) {
            // Outro técnico pode ter assumido a solicitação antes: mostra o motivo e atualiza a fila.
            toast.info(error.response.data?.message ?? 'A fila mudou. Atualizando.');
            await refresh();
        } else toast.unexpected();
    } finally {
        accepting.value = false;
    }
};
</script>

<template>
    <Head title="Painel do chat" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader title="Painel de atendimento" subtitle="Disponibilidade, fila de espera e atendimentos em andamento.">
                <ChatConnectionStatus :status="live.status.value" />
            </PageHeader>

            <div class="grid gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
                <!-- Coluna da esquerda; no celular some quando há uma conversa aberta -->
                <div class="space-y-6" :class="selectedId !== null && 'hidden lg:block'">
                    <!-- A. Disponibilidade -->
                    <Card v-if="state.agent" title="Disponibilidade">
                        <div class="space-y-4">
                            <Switch
                                :model-value="state.agent.is_available"
                                label="Disponível para novos atendimentos"
                                :disabled="togglingAvailability"
                                @update:model-value="setAvailability"
                            />
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                <dt class="text-neutral-600">Situação efetiva</dt>
                                <dd><ChatAvailabilityBadge :availability="state.agent.availability" /></dd>
                                <dt class="text-neutral-600">Atendimentos ativos</dt>
                                <dd class="font-medium text-neutral-900">{{ state.agent.active_sessions }}</dd>
                                <dt class="text-neutral-600">Limite configurado</dt>
                                <dd class="font-medium text-neutral-900">{{ state.agent.max_sessions }}</dd>
                            </dl>
                            <ChatSoundToggle :model-value="sound.enabled.value" :saving="sound.saving.value" :blocked="sound.blocked.value" @update:model-value="sound.setEnabled" @test="sound.test" />
                        </div>
                    </Card>

                    <!-- B. Fila de espera -->
                    <Card v-if="state.queue" flush>
                        <template #header>
                            <h2 class="text-lg font-semibold text-neutral-900">Fila de espera</h2>
                            <Badge :tone="state.queue.waiting ? 'info' : 'neutral'">{{ state.queue.waiting }} aguardando</Badge>
                        </template>

                        <EmptyState v-if="!state.queue.entries.length" title="Ninguém aguardando" description="Novas solicitações aparecem aqui na ordem de chegada." icon="chat" />
                        <ol v-else class="max-h-80 divide-y divide-neutral-200 overflow-y-auto" aria-label="Fila de espera, do mais antigo ao mais novo">
                            <li v-for="entry in state.queue.entries" :key="entry.position" class="flex gap-3 px-4 py-3 text-sm">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-700">{{ entry.position }}</span>
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-neutral-900">{{ entry.subject }}</p>
                                    <p class="text-xs text-neutral-600">{{ entry.department }}</p>
                                    <p class="text-xs text-neutral-500">Entrou às {{ formatTime(entry.waiting_since) }} · aguardando há {{ elapsed(entry.waiting_since, now) }}</p>
                                </div>
                            </li>
                        </ol>

                        <template v-if="can('chat.accept_next')" #footer>
                            <Button block :loading="accepting" :disabled="!canAccept" @click="acceptNext">Atender próximo</Button>
                            <p v-if="acceptHint" class="mt-2 text-center text-xs text-neutral-600">{{ acceptHint }}</p>
                        </template>
                    </Card>

                    <!-- C. Atendimentos ativos -->
                    <Card v-if="can('chat.accept_next') || can('chat.manage_availability')" title="Atendimentos ativos" flush>
                        <EmptyState v-if="!state.sessions.length" title="Nenhum atendimento em andamento" description="Use “Atender próximo” para começar." icon="chat" />
                        <ul v-else class="divide-y divide-neutral-200" aria-label="Atendimentos ativos">
                            <li v-for="session in state.sessions" :key="session.id">
                                <button
                                    type="button"
                                    class="flex w-full items-start gap-3 px-4 py-3 text-left text-sm transition-colors duration-150 hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500"
                                    :class="session.id === selectedId && 'bg-primary-50'"
                                    :aria-current="session.id === selectedId ? 'true' : undefined"
                                    @click="selectedId = session.id"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-medium text-neutral-900">{{ session.requester?.name }}</p>
                                        <p class="truncate text-xs text-neutral-600">{{ session.subject }}</p>
                                        <p class="text-xs text-neutral-500">
                                            Desde {{ formatTime(session.accepted_at!) }} · {{ elapsed(session.accepted_at!, now) }}
                                        </p>
                                    </div>
                                    <Badge v-if="session.unread" tone="primary">
                                        {{ session.unread }} <span class="sr-only">{{ session.unread === 1 ? 'mensagem não lida' : 'mensagens não lidas' }}</span>
                                    </Badge>
                                </button>
                            </li>
                        </ul>
                    </Card>
                </div>

                <!-- CHAT-05: conversa do técnico -->
                <div :class="selectedId === null && 'hidden lg:block'">
                    <Card v-if="selected" flush>
                        <template #header>
                            <div class="flex min-w-0 items-center gap-3">
                                <Button variant="ghost" size="sm" class="lg:hidden" aria-label="Voltar à lista de atendimentos" @click="selectedId = null">
                                    <Icon name="chevron-left" :size="16" />
                                </Button>
                                <div class="min-w-0">
                                    <h2 class="truncate text-lg font-semibold text-neutral-900">{{ selected.requester?.name }}</h2>
                                    <p class="truncate text-sm text-neutral-600">{{ selected.subject }}</p>
                                </div>
                            </div>
                            <Button v-if="closure && can('chat.close')" variant="secondary" size="sm" @click="closing = true">Encerrar atendimento</Button>
                        </template>
                        <div class="h-[70vh] min-h-[26rem]">
                            <ChatConversation
                                :messages="conversation.messages.value"
                                :current-user-id="userId"
                                :closed="conversation.closed.value"
                                :loading="conversation.loading.value"
                                :max-length="config.messageMaxLength"
                                @send="conversation.send"
                                @retry="conversation.retry"
                                @discard="conversation.discard"
                            />
                        </div>
                    </Card>
                    <Card v-else>
                        <EmptyState title="Selecione um atendimento" description="A conversa aparece aqui. Você pode alternar entre os atendimentos ativos sem perder o que já foi escrito." icon="chat" />
                    </Card>
                </div>
            </div>
        </div>

        <!-- Fica fora do cartão da conversa: ao encerrar, a sessão sai da lista e o modal continua mostrando o chamado gerado -->
        <CloseChatModal v-if="closure" :show="closing" :session="closingSession ?? { id: 0, subject: '' }" :form="closure" @close="closing = false" @ended="onEnded" />
    </AuthenticatedLayout>
</template>
