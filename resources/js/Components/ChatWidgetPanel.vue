<script setup lang="ts">
import { computed, nextTick, ref, toRef, watch } from 'vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import ChatConnectionStatus from '@/Components/ChatConnectionStatus.vue';
import ChatConversation from '@/Components/ChatConversation.vue';
import ChatQueuePosition from '@/Components/ChatQueuePosition.vue';
import ChatSoundToggle from '@/Components/ChatSoundToggle.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import { useRequesterChat } from '@/composables/useRequesterChat';
import type { ChatSessionState } from '@/lib/chat';

/**
 * Painel do botão flutuante (solicitante): o mesmo fluxo da página `/chat` (assunto → fila → conversa →
 * encerrada), em formato de janela. Fica montado enquanto houver atendimento aberto, mesmo minimizado, para
 * os avisos em tempo real continuarem chegando; `open` só controla se está à vista.
 */
const props = defineProps<{ initial: ChatSessionState | null; open: boolean }>();

const emit = defineEmits<{
    close: [];
    /** Resumo para o botão: situação, não lidas e posição na fila. */
    summary: [value: { status: string | null; unread: number; position: number | null }];
}>();

const {
    config, userId, sound, live, session, subject, errors, requesting, cancelling, confirmCancel, unavailable,
    unread, status, conversation, closed, requestChat, cancel, newRequest,
} = useRequesterChat(props.initial, toRef(props, 'open'));

watch(
    [status, unread, () => session.value?.queue?.position ?? null],
    ([state, count, position]) => emit('summary', { status: state, unread: count, position }),
    { immediate: true },
);

const panel = ref<HTMLElement | null>(null);

// Ao abrir, o foco vai para o painel (sem rolar nem abrir teclado no celular); Esc minimiza.
watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        await nextTick();
        panel.value?.focus({ preventScroll: true });
    },
);

const subtitle = computed(() => {
    if (status.value === 'waiting') return 'Aguardando atendimento';
    if (status.value === 'active') return `Com ${session.value?.technician?.name ?? 'um técnico'}`;
    if (status.value === 'ended') return 'Atendimento encerrado';

    return 'Fale com a nossa equipe';
});
</script>

<template>
    <section
        v-show="open"
        id="chat-widget-panel"
        ref="panel"
        role="dialog"
        aria-label="Atendimento via chat"
        tabindex="-1"
        class="fixed inset-0 z-40 flex flex-col overflow-hidden bg-white shadow-xl focus:outline-none sm:inset-auto sm:bottom-20 sm:right-4 sm:h-[34rem] sm:max-h-[calc(100vh-6rem)] sm:w-96 sm:rounded-lg sm:border sm:border-neutral-200"
        @keydown.esc.stop="emit('close')"
    >
        <header class="flex items-center gap-3 bg-primary-500 px-4 py-3 text-white">
            <Icon name="chat" :size="20" class="shrink-0" />
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-sm font-semibold">Atendimento via chat</h2>
                <p class="truncate text-xs text-primary-100">{{ subtitle }}</p>
            </div>
            <button
                type="button"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md hover:bg-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                aria-label="Minimizar o chat"
                @click="emit('close')"
            >
                <Icon name="x" :size="20" />
            </button>
        </header>

        <div class="flex min-h-0 flex-1 flex-col bg-neutral-50">
            <!-- Pedido: só o assunto (D-C5) -->
            <template v-if="!session">
                <div class="flex-1 space-y-3 overflow-y-auto p-4">
                    <div class="max-w-[85%] rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900">
                        Olá! Sobre o que você precisa de ajuda? Conte em poucas palavras. Um técnico atende você assim que possível.
                    </div>

                    <Alert v-if="unavailable" variant="warning" title="Nenhum técnico disponível">
                        {{ unavailable }}
                        <div class="mt-3">
                            <Button size="sm" :href="route('tickets.create')">Abrir chamado tradicional</Button>
                        </div>
                    </Alert>
                    <p v-if="errors.subject" class="text-sm text-danger-text" role="alert">{{ errors.subject }}</p>
                    <p v-if="errors.department" class="text-sm text-danger-text" role="alert">{{ errors.department }}</p>
                </div>

                <form class="border-t border-neutral-200 bg-white p-3" novalidate @submit.prevent="requestChat">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-900" for="chat-widget-subject">Digite o assunto do seu atendimento</label>
                    <div class="flex items-center gap-2">
                        <input
                            id="chat-widget-subject"
                            v-model="subject"
                            type="text"
                            autocomplete="off"
                            :maxlength="config.subjectMaxLength"
                            placeholder="Descreva o assunto…"
                            class="h-10 min-w-0 flex-1 rounded-full border-neutral-300 px-4 text-sm shadow-sm placeholder:text-neutral-400 focus:border-primary-500 focus:ring-primary-500"
                        />
                        <Button type="submit" :loading="requesting" :disabled="!subject.trim()" aria-label="Solicitar atendimento">
                            <Icon v-if="!requesting" name="paper-airplane" :size="20" />
                        </Button>
                    </div>
                    <p class="mt-2 text-center text-xs text-neutral-600">
                        Prefere registrar e acompanhar depois?
                        <a :href="route('tickets.create')" class="font-medium text-primary-600 underline">Abrir chamado</a>
                    </p>
                </form>
            </template>

            <!-- Fila -->
            <template v-else-if="status === 'waiting'">
                <div class="flex-1 space-y-5 overflow-y-auto p-4">
                    <ChatQueuePosition v-if="session.queue" :position="session.queue.position" />
                    <p class="text-center text-sm text-neutral-600">
                        Assunto: <span class="font-medium text-neutral-900">{{ session.subject }}</span>
                    </p>
                    <Alert v-if="session.no_technician_on_duty" variant="warning" title="Nenhum técnico disponível no momento">
                        Sua solicitação continua na fila. Se preferir, abra um chamado e acompanhe por ele.
                        <div class="mt-3">
                            <Button size="sm" variant="secondary" :href="route('tickets.create')">Abrir chamado tradicional</Button>
                        </div>
                    </Alert>
                </div>
                <div class="space-y-3 border-t border-neutral-200 bg-white p-3">
                    <div class="flex items-center justify-between gap-2">
                        <ChatConnectionStatus :status="live.status.value" />
                        <Button variant="secondary" size="sm" @click="confirmCancel = true">Cancelar solicitação</Button>
                    </div>
                    <ChatSoundToggle :model-value="sound.enabled.value" :saving="sound.saving.value" :blocked="sound.blocked.value" @update:model-value="sound.setEnabled" @test="sound.test" />
                </div>
            </template>

            <!-- Conversa -->
            <template v-else>
                <Alert v-if="status === 'ended'" variant="success" title="Atendimento encerrado" class="m-3 mb-0">
                    <template v-if="session.ticket_number">
                        <template v-if="session.outcome">Marcado como <strong>{{ session.outcome.label.toLowerCase() }}</strong>. </template>
                        Chamado <strong class="font-mono">{{ session.ticket_number }}</strong>.
                    </template>
                    <template v-else>A conversa fica no seu histórico.</template>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <Button v-if="session.ticket_number" size="sm" :href="route('tickets.show', { ticket: session.ticket_number })">Ver o chamado</Button>
                        <Button size="sm" variant="secondary" @click="newRequest">Novo atendimento</Button>
                    </div>
                </Alert>
                <div class="min-h-0 flex-1">
                    <ChatConversation
                        :messages="conversation.messages.value"
                        :current-user-id="userId"
                        :closed="closed"
                        :loading="conversation.loading.value"
                        :max-length="config.messageMaxLength"
                        @send="conversation.send"
                        @retry="conversation.retry"
                        @discard="conversation.discard"
                    />
                </div>
                <div class="border-t border-neutral-200 bg-white px-3 py-2">
                    <ChatConnectionStatus :status="live.status.value" />
                </div>
            </template>
        </div>

        <ConfirmModal
            :show="confirmCancel"
            title="Cancelar a solicitação?"
            message="Você sai da fila e perde a sua posição. Se precisar de ajuda depois, será preciso solicitar de novo."
            confirm-label="Cancelar solicitação"
            cancel-label="Continuar na fila"
            variant="danger"
            :processing="cancelling"
            @close="confirmCancel = false"
            @confirm="cancel"
        />
    </section>
</template>
