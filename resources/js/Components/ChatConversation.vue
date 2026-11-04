<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import Button from '@/Components/Button.vue';
import Icon from '@/Components/Icon.vue';
import { formatTime, type ChatMessage, type PendingMessage } from '@/lib/chat';

type Item = (ChatMessage & { status: 'sent' }) | PendingMessage;

const props = defineProps<{
    messages: Item[];
    /** Quem está lendo: suas mensagens ficam à direita. */
    currentUserId: number;
    /** O atendimento não aceita mais mensagens (encerrado ou recusado pelo servidor). */
    closed?: boolean;
    maxLength: number;
    loading?: boolean;
    /** Texto exibido no lugar do campo quando fechado. */
    closedMessage?: string;
}>();

const emit = defineEmits<{ send: [body: string]; retry: [clientId: string]; discard: [clientId: string] }>();

const draft = ref('');
const list = ref<HTMLElement | null>(null);
const input = ref<HTMLTextAreaElement | null>(null);

const isMine = (item: Item) => ('author' in item ? item.author.id === props.currentUserId : true);
const authorOf = (item: Item) => ('author' in item ? item.author.name : 'Você');
const keyOf = (item: Item) => item.client_id;

const canSend = computed(() => !props.closed && draft.value.trim().length > 0 && draft.value.length <= props.maxLength);

const submit = () => {
    if (!canSend.value) return;
    emit('send', draft.value);
    draft.value = '';
    input.value?.focus();
};

/** Enter envia; Shift+Enter quebra a linha. */
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    }
};

// Acompanha a conversa só quando a pessoa já está perto do fim, para não tirá-la do que está lendo.
watch(
    () => props.messages.length,
    async () => {
        const el = list.value;
        const nearEnd = !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120;
        await nextTick();
        if (el && (nearEnd || props.messages.at(-1)?.status !== 'sent')) el.scrollTop = el.scrollHeight;
    },
    { flush: 'post' },
);
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- role="log": leitores de tela anunciam mensagens novas sem mover o foco -->
        <div
            ref="list"
            role="log"
            aria-live="polite"
            aria-label="Mensagens do atendimento"
            class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4"
            tabindex="0"
        >
            <p v-if="!messages.length && !loading" class="py-8 text-center text-sm text-neutral-500">
                {{ closed ? 'Nenhuma mensagem foi trocada.' : 'Nenhuma mensagem ainda. Escreva a primeira.' }}
            </p>

            <div v-for="item in messages" :key="keyOf(item)" class="flex" :class="isMine(item) ? 'justify-end' : 'justify-start'">
                <div class="max-w-[85%] sm:max-w-[70%]">
                    <div
                        class="rounded-lg px-3 py-2 text-sm"
                        :class="[
                            isMine(item) ? 'bg-primary-500 text-white' : 'border border-neutral-200 bg-white text-neutral-900',
                            item.status === 'failed' && 'opacity-70',
                        ]"
                    >
                        <p class="mb-0.5 text-xs font-semibold" :class="isMine(item) ? 'text-primary-100' : 'text-neutral-600'">
                            {{ authorOf(item) }}
                        </p>
                        <!-- Texto puro: interpolação, nunca HTML -->
                        <p class="whitespace-pre-wrap break-words">{{ item.body }}</p>
                    </div>
                    <p class="mt-1 text-xs text-neutral-500" :class="isMine(item) && 'text-right'">
                        <template v-if="item.status === 'sending'">Enviando…</template>
                        <template v-else-if="item.status === 'failed'">
                            <span class="text-danger-text">Não enviada. {{ item.error }}</span>
                            <button type="button" class="ms-2 font-medium text-primary-600 underline" @click="emit('retry', item.client_id)">
                                Tentar novamente
                            </button>
                            <button type="button" class="ms-2 text-neutral-600 underline" @click="emit('discard', item.client_id)">Descartar</button>
                        </template>
                        <template v-else>{{ formatTime(item.created_at) }}</template>
                    </p>
                </div>
            </div>
        </div>

        <form class="border-t border-neutral-200 bg-white p-3" novalidate @submit.prevent="submit">
            <p v-if="closed" class="py-2 text-center text-sm text-neutral-600">
                {{ closedMessage ?? 'Este atendimento foi encerrado. Não é possível enviar novas mensagens.' }}
            </p>
            <div v-else class="flex items-end gap-2">
                <label class="sr-only" for="chat-message-input">Mensagem</label>
                <textarea
                    id="chat-message-input"
                    ref="input"
                    v-model="draft"
                    rows="2"
                    class="block max-h-32 w-full resize-none rounded-md border-neutral-300 text-sm shadow-sm placeholder:text-neutral-400 focus:border-primary-500 focus:ring-primary-500"
                    placeholder="Escreva sua mensagem. Enter envia; Shift+Enter quebra a linha."
                    :maxlength="maxLength"
                    @keydown="onKeydown"
                />
                <Button type="submit" :disabled="!canSend" aria-label="Enviar mensagem">
                    <Icon name="paper-airplane" :size="20" />
                    <span class="hidden sm:inline">Enviar</span>
                </Button>
            </div>
            <p v-if="!closed && draft.length > maxLength * 0.9" class="mt-1 text-right text-xs text-neutral-500">
                {{ draft.length.toLocaleString('pt-BR') }} / {{ maxLength.toLocaleString('pt-BR') }}
            </p>
        </form>
    </div>
</template>
