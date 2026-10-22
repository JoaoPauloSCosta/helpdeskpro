<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { isAxiosError } from 'axios';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Spinner from '@/Components/Spinner.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import Timeline from '@/Components/Timeline.vue';
import TimelineItem from '@/Components/TimelineItem.vue';
import {
    formatDate,
    formatDateTime,
    formatDuration,
    type TicketAuditDetail,
    type TicketAuditEvent,
    type TicketAuditPage,
    type TicketDetail,
} from '@/lib/ticket';

/**
 * Histórico de auditoria (CHA-14). Somente leitura: carregado ao abrir a tela e recarregado quando
 * o chamado muda. O backend confere `audit.view` e o acesso ao chamado a cada consulta, pagina em
 * ordem cronológica e já envia só os dados autorizados; aqui há apenas formatação.
 */
const props = defineProps<{
    ticket: TicketDetail;
    /** `can.viewAudit` da tela; sem ele, nenhuma consulta é feita. */
    allowed: boolean;
}>();

type State = 'loading' | 'loaded' | 'denied' | 'error';

const state = ref<State>('loading');
const events = ref<TicketAuditEvent[]>([]);
const meta = ref<TicketAuditPage['meta'] | null>(null);
/** Falha ao buscar a página seguinte; os eventos já carregados continuam visíveis. */
const moreError = ref(false);
const loadingMore = ref(false);
const expanded = ref(new Set<number>());
/** Ignora respostas de uma consulta anterior que cheguem depois de outra. */
let request = 0;

const fetchPage = (page: number) =>
    window.axios.get<TicketAuditPage>(route('tickets.audit.index', props.ticket.number), { params: { page } });

const statusOf = (error: unknown) => (isAxiosError(error) ? error.response?.status : undefined);

const load = async () => {
    const id = ++request;
    state.value = 'loading';
    moreError.value = false;
    loadingMore.value = false;

    try {
        const { data } = await fetchPage(1);
        if (id !== request) return;
        events.value = data.events;
        meta.value = data.meta;
        state.value = 'loaded';
    } catch (error) {
        if (id !== request) return;
        events.value = [];
        meta.value = null;
        // 403: o usuário perdeu `audit.view` ou o acesso ao chamado.
        const status = statusOf(error);
        state.value = status === 403 || status === 404 ? 'denied' : 'error';
    }
};

const loadMore = async () => {
    if (!meta.value || loadingMore.value) return;

    const id = request;
    loadingMore.value = true;
    moreError.value = false;

    try {
        const { data } = await fetchPage(meta.value.current_page + 1);
        if (id !== request) return;
        // Eventos novos podem deslocar a paginação; um mesmo evento não aparece duas vezes.
        const known = new Set(events.value.map((event) => event.id));
        events.value.push(...data.events.filter((event) => !known.has(event.id)));
        meta.value = data.meta;
    } catch (error) {
        if (id !== request) return;
        const status = statusOf(error);
        if (status === 403 || status === 404) state.value = 'denied';
        else moreError.value = true;
    } finally {
        if (id === request) loadingMore.value = false;
    }
};

onMounted(() => props.allowed && load());

// Depois de uma operação no chamado (status, atribuição, comentário...), a página é recarregada.
watch(
    () => [props.ticket.status, props.ticket.priority, props.ticket.updated_at],
    () => props.allowed && load(),
);

const hasMore = computed(() => !!meta.value && meta.value.current_page < meta.value.last_page);

const countLabel = computed(() => {
    const total = meta.value?.total ?? 0;
    return total === 1 ? '1 evento registrado' : `${total} eventos registrados`;
});

const toggle = (id: number) => {
    const next = new Set(expanded.value);
    if (!next.delete(id)) next.add(id);
    expanded.value = next;
};

const hasDetails = (event: TicketAuditEvent) => event.details.length > 0 || event.restricted;

const isEmpty = (event: TicketAuditEvent) => !event.description && event.changes.length === 0 && !hasDetails(event);

const formatDetail = (detail: TicketAuditDetail): string => {
    switch (detail.format) {
        case 'datetime':
            return formatDateTime(String(detail.value));
        case 'date':
            return formatDate(String(detail.value));
        case 'duration':
            return formatDuration(Number(detail.value));
        default:
            return String(detail.value);
    }
};
</script>

<template>
    <Card>
        <template #header>
            <div class="min-w-0">
                <h2 id="audit-history-title" class="text-lg font-semibold text-neutral-900">Histórico de auditoria</h2>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-1 text-xs text-neutral-600">
                    Registros de rastreabilidade de <TicketNumber :number="ticket.number" />
                </p>
            </div>
            <Badge class="shrink-0">
                <Icon name="lock" :size="16" />
                Somente leitura
            </Badge>
        </template>

        <div aria-labelledby="audit-history-title" class="space-y-4">
            <EmptyState
                v-if="!allowed"
                icon="lock"
                title="Histórico restrito"
                description="O histórico de auditoria é exibido somente para quem tem permissão para consultar a auditoria."
            />

            <div
                v-else-if="state === 'loading' && !meta"
                role="status"
                class="flex items-center justify-center gap-2 py-12 text-sm text-neutral-600"
            >
                <Spinner :size="20" />
                Carregando histórico de auditoria…
            </div>

            <EmptyState
                v-else-if="state === 'denied'"
                icon="lock"
                title="Histórico indisponível"
                description="Você não tem permissão para consultar o histórico de auditoria deste chamado."
            />

            <Alert v-else-if="state === 'error'" variant="danger" title="Não foi possível carregar o histórico de auditoria">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="load">
                    <Icon name="arrow-path" :size="16" /> Tentar novamente
                </Button>
            </Alert>

            <template v-else-if="meta">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-neutral-500">{{ countLabel }} · em ordem cronológica</p>
                    <p v-if="state === 'loading'" role="status" class="inline-flex items-center gap-2 text-xs text-neutral-500">
                        <Spinner :size="16" /> Atualizando…
                    </p>
                </div>

                <EmptyState
                    v-if="events.length === 0"
                    icon="clock"
                    title="Nenhum evento registrado"
                    description="Os eventos aparecem aqui à medida que as operações do chamado são registradas."
                />

                <Timeline v-else>
                    <TimelineItem
                        v-for="event in events"
                        :key="event.id"
                        :title="event.title"
                        :author="event.author ?? 'Usuário não identificado'"
                        :date="formatDateTime(event.created_at)"
                        :tone="event.tone"
                    >
                        <p v-if="event.description">{{ event.description }}</p>

                        <!-- Valores anteriores e novos, como gravados no evento -->
                        <dl v-if="event.changes.length" class="space-y-0.5" :class="event.description && 'mt-1'">
                            <div v-for="change in event.changes" :key="change.field" class="flex flex-wrap gap-x-1">
                                <dt class="font-medium text-neutral-700">{{ change.field }}:</dt>
                                <dd class="min-w-0 break-words">
                                    <template v-if="change.from !== undefined">
                                        <span v-if="change.from !== null">{{ change.from }}</span>
                                        <span v-else class="italic text-neutral-500">não registrado</span>
                                        <span aria-hidden="true"> → </span>
                                        <span class="sr-only"> para </span>
                                    </template>
                                    <span v-if="change.to !== null" class="text-neutral-900">{{ change.to }}</span>
                                    <span v-else class="italic text-neutral-500">não registrado</span>
                                </dd>
                            </div>
                        </dl>

                        <p v-if="isEmpty(event)" class="text-xs text-neutral-500">Sem detalhes adicionais registrados.</p>

                        <template v-if="hasDetails(event)">
                            <button
                                type="button"
                                class="-ms-1 mt-1 inline-flex items-center gap-1 rounded px-1 text-xs font-medium text-primary-600 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                :aria-expanded="expanded.has(event.id)"
                                :aria-controls="`audit-event-${event.id}`"
                                @click="toggle(event.id)"
                            >
                                <Icon :name="expanded.has(event.id) ? 'chevron-down' : 'chevron-right'" :size="16" />
                                {{ expanded.has(event.id) ? 'Ocultar detalhes' : 'Ver detalhes' }}
                            </button>

                            <div
                                v-if="expanded.has(event.id)"
                                :id="`audit-event-${event.id}`"
                                class="mt-2 space-y-3 rounded-md border border-neutral-200 bg-neutral-50 p-3"
                            >
                                <!-- Texto do usuário sempre como texto, nunca como HTML -->
                                <dl v-if="event.details.length" class="space-y-2">
                                    <div v-for="detail in event.details" :key="detail.label" class="min-w-0">
                                        <dt class="text-xs font-medium text-neutral-500">{{ detail.label }}</dt>
                                        <dd
                                            class="mt-0.5 break-words text-neutral-900"
                                            :class="detail.format === 'longtext' && 'whitespace-pre-line'"
                                        >
                                            {{ formatDetail(detail) }}
                                        </dd>
                                    </div>
                                </dl>
                                <p v-if="event.restricted" class="inline-flex items-start gap-1 text-xs text-neutral-600">
                                    <Icon name="lock" :size="16" class="shrink-0" />
                                    O motivo e os dados da pendência são exibidos somente à equipe de suporte.
                                </p>
                            </div>
                        </template>
                    </TimelineItem>
                </Timeline>

                <Alert v-if="moreError" variant="danger" title="Não foi possível carregar mais eventos">
                    <Button variant="secondary" size="sm" class="mt-1" @click="loadMore">
                        <Icon name="arrow-path" :size="16" /> Tentar novamente
                    </Button>
                </Alert>

                <div v-else-if="hasMore" class="flex flex-col items-center gap-2 border-t border-neutral-200 pt-4">
                    <p class="text-xs text-neutral-500">Exibindo {{ events.length }} de {{ meta.total }}</p>
                    <Button variant="secondary" size="sm" :loading="loadingMore" @click="loadMore">
                        {{ loadingMore ? 'Carregando…' : 'Carregar mais eventos' }}
                    </Button>
                </div>

                <p class="text-xs text-neutral-500">
                    Somente eventos efetivamente registrados são exibidos; operações sem registro não são reconstruídas.
                </p>
            </template>
        </div>
    </Card>
</template>
