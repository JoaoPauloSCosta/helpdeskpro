<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { isAxiosError } from 'axios';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import Spinner from '@/Components/Spinner.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import {
    formatDateTime,
    type TicketCommentHistory,
    type TicketCommentItem,
    type TicketDetail,
} from '@/lib/ticket';

/**
 * Histórico de versões de um comentário (CHA-12). Somente leitura: as versões são carregadas ao
 * abrir, e o backend confere o acesso ao chamado e ao comentário a cada consulta.
 */
const props = defineProps<{
    show: boolean;
    ticket: TicketDetail;
    /** Comentário cujo histórico foi pedido; `null` com o modal fechado. */
    comment: TicketCommentItem | null;
}>();

const emit = defineEmits<{ close: [] }>();

type State = 'loading' | 'loaded' | 'denied' | 'error';

const state = ref<State>('loading');
const history = ref<TicketCommentHistory | null>(null);
/** Ignora respostas de uma abertura anterior que cheguem depois de outra. */
let request = 0;

const load = async () => {
    if (!props.comment) return;

    const id = ++request;
    state.value = 'loading';
    history.value = null;

    try {
        const { data } = await window.axios.get<TicketCommentHistory>(
            route('tickets.comments.versions.index', [props.ticket.number, props.comment.id]),
        );
        if (id !== request) return;
        history.value = data;
        state.value = 'loaded';
    } catch (error) {
        if (id !== request) return;
        // 403: sem acesso ao chamado; 404: comentário inexistente ou fora do alcance do usuário.
        const status = isAxiosError(error) ? error.response?.status : undefined;
        state.value = status === 403 || status === 404 ? 'denied' : 'error';
    }
};

watch(
    () => props.show,
    (open) => {
        if (open) load();
        else request++;
    },
);

const previousCount = computed(() => (history.value ? history.value.versions.length - 1 : 0));
</script>

<template>
    <Modal :show="show" max-width="xl" @close="emit('close')">
        <div class="flex max-h-[90vh] flex-col">
            <div class="space-y-4 overflow-y-auto p-4 sm:p-6">
                <div>
                    <h2 id="comment-history-title" class="text-lg font-semibold text-neutral-900">Histórico de versões</h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        Conteúdos registrados a cada edição desta mensagem, do mais recente ao original.
                    </p>
                </div>

                <!-- Contexto do chamado -->
                <div class="flex flex-wrap items-center gap-2 rounded-md border border-neutral-200 bg-neutral-50 p-3">
                    <TicketNumber :number="ticket.number" />
                    <span v-if="comment?.internal" class="inline-flex items-center gap-1 text-xs font-medium text-warning-text">
                        <Icon name="lock" :size="16" />
                        Nota interna · não visível ao solicitante
                    </span>
                    <span v-else class="text-xs text-neutral-600">Comentário público</span>
                </div>

                <div v-if="state === 'loading'" role="status" class="flex items-center justify-center gap-2 py-12 text-sm text-neutral-600">
                    <Spinner :size="20" />
                    Carregando versões…
                </div>

                <EmptyState
                    v-else-if="state === 'denied'"
                    icon="lock"
                    title="Histórico indisponível"
                    description="Você não tem permissão para consultar o histórico desta mensagem."
                />

                <Alert v-else-if="state === 'error'" variant="danger" title="Não foi possível carregar o histórico">
                    <p>Verifique sua conexão e tente novamente.</p>
                    <Button variant="secondary" size="sm" class="mt-3" @click="load">
                        <Icon name="arrow-path" :size="16" /> Tentar novamente
                    </Button>
                </Alert>

                <template v-else-if="history">
                    <p v-if="previousCount === 0" class="rounded-md border border-neutral-200 bg-white p-3 text-sm text-neutral-600">
                        Nenhuma versão anterior registrada para esta mensagem.
                    </p>

                    <ol class="space-y-3" aria-labelledby="comment-history-title">
                        <li v-for="version in history.versions" :key="version.number">
                            <article
                                class="rounded-lg border p-4"
                                :class="version.internal ? 'border-warning-border bg-warning-bg' : 'border-neutral-200 bg-white'"
                            >
                                <header class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <h3 class="text-sm font-semibold text-neutral-900">Versão {{ version.number }}</h3>
                                    <Badge v-if="version.current" tone="primary">Atual</Badge>
                                    <Badge v-if="version.original" tone="neutral">Original</Badge>
                                    <span v-if="version.internal" class="inline-flex items-center gap-1 text-xs font-medium text-warning-text">
                                        <Icon name="lock" :size="16" />
                                        Nota interna
                                    </span>
                                </header>
                                <p class="mt-1 text-xs text-neutral-600">
                                    {{ version.original ? 'Escrito' : 'Editado' }}<template v-if="version.author"> por <span class="font-medium text-neutral-900">{{ version.author }}</span></template>
                                    em <time :datetime="version.recorded_at ?? undefined">{{ formatDateTime(version.recorded_at) }}</time>
                                </p>
                                <!-- Interpolação de texto: o conteúdo do usuário nunca é tratado como HTML -->
                                <p class="mt-2 whitespace-pre-line break-words text-sm text-neutral-900">{{ version.body }}</p>
                            </article>
                        </li>
                    </ol>

                    <p class="inline-flex items-center gap-1 text-xs text-neutral-500">
                        <Icon name="lock" :size="16" />
                        Somente leitura: versões registradas não podem ser alteradas.
                    </p>
                </template>
            </div>

            <div class="flex justify-end border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:px-6">
                <Button variant="secondary" class="w-full sm:w-auto" @click="emit('close')">Fechar</Button>
            </div>
        </div>
    </Modal>
</template>
