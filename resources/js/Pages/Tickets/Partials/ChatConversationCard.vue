<script setup lang="ts">
import axios from 'axios';
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ChatConversation from '@/Components/ChatConversation.vue';
import Icon from '@/Components/Icon.vue';
import type { ChatMessage, TicketChatSummary } from '@/lib/chat';
import { formatDateTime } from '@/lib/ticket';
import type { PageProps } from '@/types';

/**
 * Conversa do atendimento via chat que gerou o chamado (RF-34). Somente leitura e sob demanda: o servidor
 * confere o acesso ao chamado a cada leitura. As mensagens são exibidas como texto, nunca como HTML.
 */
const props = defineProps<{ ticket: string; chat: TicketChatSummary }>();

const page = usePage<PageProps>();
const messages = ref<(ChatMessage & { status: 'sent' })[] | null>(null);
const loading = ref(false);
const failed = ref(false);
const open = computed(() => messages.value !== null);

const load = async () => {
    loading.value = true;
    failed.value = false;

    try {
        const { data } = await axios.get<{ messages: ChatMessage[] }>(route('tickets.chat.show', { ticket: props.ticket }));
        messages.value = data.messages.map((message) => ({ ...message, status: 'sent' as const }));
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <Card>
        <template #header>
            <h2 class="text-lg font-semibold text-neutral-900">Conversa do atendimento via chat</h2>
        </template>

        <p class="text-sm text-neutral-700">
            Atendimento<template v-if="chat.technician"> com <span class="font-medium text-neutral-900">{{ chat.technician }}</span></template>,
            solicitado em {{ formatDateTime(chat.started_at) }}<template v-if="chat.ended_at"> e encerrado em {{ formatDateTime(chat.ended_at) }}</template>.
            {{ chat.messages === 1 ? '1 mensagem trocada.' : `${chat.messages} mensagens trocadas.` }}
        </p>

        <Alert v-if="failed" variant="warning" class="mt-3" title="Não foi possível carregar a conversa">Tente novamente em instantes.</Alert>

        <Button v-if="!open && chat.messages > 0" class="mt-3" variant="secondary" :loading="loading" @click="load">
            <Icon name="chat" :size="16" /> Ver conversa
        </Button>

        <div v-if="open" class="mt-4 h-[28rem] overflow-hidden rounded-md border border-neutral-200">
            <ChatConversation
                :messages="messages!"
                :current-user-id="page.props.auth.user.id"
                closed
                closed-message="Conversa encerrada. Somente leitura."
                :max-length="0"
            />
        </div>
    </Card>
</template>
