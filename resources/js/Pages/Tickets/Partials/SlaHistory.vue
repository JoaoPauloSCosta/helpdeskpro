<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { isAxiosError } from 'axios';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Spinner from '@/Components/Spinner.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Timeline from '@/Components/Timeline.vue';
import TimelineItem from '@/Components/TimelineItem.vue';
import {
    formatDate,
    formatDateTime,
    formatDuration,
    type TicketDetail,
    type TicketSlaEvent,
    type TicketSlaHistory,
    type TicketSlaPauseItem,
} from '@/lib/ticket';

/**
 * Histórico de SLA (CHA-13). Somente leitura: carregado ao expandir a seção e recarregado quando o
 * chamado muda. O backend confere o acesso a cada consulta; nada é calculado ou estimado aqui além
 * da formatação das durações gravadas.
 */
const props = defineProps<{ ticket: TicketDetail }>();

type State = 'loading' | 'loaded' | 'denied' | 'error';

const expanded = ref(false);
const state = ref<State>('loading');
const history = ref<TicketSlaHistory | null>(null);
/** Ignora respostas de uma consulta anterior que cheguem depois de outra. */
let request = 0;

const load = async () => {
    const id = ++request;
    state.value = 'loading';

    try {
        const { data } = await window.axios.get<TicketSlaHistory>(route('tickets.sla-history.index', props.ticket.number));
        if (id !== request) return;
        history.value = data;
        state.value = 'loaded';
    } catch (error) {
        if (id !== request) return;
        history.value = null;
        // 403: o usuário perdeu o acesso ao chamado.
        const status = isAxiosError(error) ? error.response?.status : undefined;
        state.value = status === 403 || status === 404 ? 'denied' : 'error';
    }
};

const toggle = () => {
    expanded.value = !expanded.value;
    if (expanded.value) load();
    else request++;
};

// Depois de uma operação no chamado (pendência, retomada, resolução...), a página é recarregada.
watch(
    () => [props.ticket.status, props.ticket.priority, props.ticket.updated_at],
    () => expanded.value && load(),
);

const activePause = computed(() => history.value?.pauses.items.find((pause) => pause.ended_at === null) ?? null);

/** [rótulo, valor] dos dados da pendência enviados à equipe de suporte; vazios são omitidos. */
const pauseDetails = (pause: TicketSlaPauseItem) =>
    (
        [
            ['Fornecedor ou terceiro', pause.supplier_name],
            ['Departamento aguardado', pause.related_department],
            ['Protocolo externo', pause.protocol_number],
            ['Responsável externo ou contato', pause.responsible_name],
            ['Previsão de retorno', pause.expected_return_at && formatDate(pause.expected_return_at)],
            ['Registrada por', pause.author],
        ] as [string, string | null | undefined][]
    ).filter(([, value]) => value);

const eventTitle: Record<TicketSlaEvent['kind'], string> = {
    pause_started: 'Contagem pausada',
    resumed: 'Contagem retomada',
    priority_changed: 'Prioridade alterada',
    resolved: 'Chamado resolvido',
    reopened: 'Chamado reaberto',
    closed: 'Chamado encerrado',
};

/** Azul: informativo e retomada · laranja: pausa · verde/vermelho: só com prazo gravado no evento. */
const eventTone = (event: TicketSlaEvent) => {
    switch (event.kind) {
        case 'pause_started':
            return 'warning';
        case 'resolved':
            return event.on_time === null ? 'primary' : event.on_time ? 'success' : 'danger';
        case 'closed':
            return 'neutral';
        default:
            return 'primary';
    }
};
</script>

<template>
    <Card>
        <template #header>
            <div class="min-w-0">
                <h2 id="sla-history-title" class="text-lg font-semibold text-neutral-900">Histórico de SLA</h2>
                <p class="mt-0.5 text-xs text-neutral-600">Pausas, retomadas e eventos registrados que afetam os prazos.</p>
            </div>
            <Button
                variant="secondary"
                size="sm"
                class="shrink-0"
                :aria-expanded="expanded"
                aria-controls="sla-history-body"
                @click="toggle"
            >
                <Icon :name="expanded ? 'chevron-down' : 'chevron-right'" :size="16" />
                {{ expanded ? 'Ocultar' : 'Exibir' }}
            </Button>
        </template>

        <div v-if="!expanded" class="text-sm text-neutral-600">
            Exiba para consultar os períodos de pausa do SLA de resolução e a linha do tempo dos eventos.
        </div>

        <div v-else id="sla-history-body" class="space-y-6" aria-labelledby="sla-history-title">
            <div
                v-if="state === 'loading' && !history"
                role="status"
                class="flex items-center justify-center gap-2 py-12 text-sm text-neutral-600"
            >
                <Spinner :size="20" />
                Carregando histórico de SLA…
            </div>

            <EmptyState
                v-else-if="state === 'denied'"
                icon="lock"
                title="Histórico indisponível"
                description="Você não tem permissão para consultar o histórico de SLA deste chamado."
            />

            <Alert v-else-if="state === 'error'" variant="danger" title="Não foi possível carregar o histórico de SLA">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="load">
                    <Icon name="arrow-path" :size="16" /> Tentar novamente
                </Button>
            </Alert>

            <template v-else-if="history">
                <p v-if="state === 'loading'" role="status" class="inline-flex items-center gap-2 text-xs text-neutral-500">
                    <Spinner :size="16" /> Atualizando…
                </p>

                <!-- Dados parciais: o motor de SLA (políticas e metas) ainda não existe -->
                <Alert variant="info" title="Dados parciais">
                    Política aplicada, meta, tempo consumido e tempo restante ainda não são registrados pelo sistema e
                    por isso não são exibidos. Os prazos e as situações acima vêm somente do que foi gravado.
                </Alert>

                <!-- Resumo das pausas: contagens e somas das durações gravadas -->
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium text-neutral-500">Abertura do chamado</dt>
                        <dd class="mt-0.5 text-neutral-900">{{ formatDateTime(ticket.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-neutral-500">Pausas registradas</dt>
                        <dd class="mt-0.5" :class="history.pauses.count ? 'text-neutral-900' : 'text-neutral-500'">
                            {{ history.pauses.count || 'Nenhuma' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-neutral-500">Tempo em pausas encerradas</dt>
                        <dd class="mt-0.5" :class="history.pauses.closed_seconds !== null ? 'text-neutral-900' : 'text-neutral-500'">
                            {{ history.pauses.closed_seconds !== null ? formatDuration(history.pauses.closed_seconds) : '—' }}
                        </dd>
                    </div>
                </dl>

                <Alert v-if="activePause" variant="warning" title="SLA de resolução pausado">
                    Pausa em andamento desde {{ formatDateTime(activePause.started_at) }} ({{ activePause.type }}).
                </Alert>

                <!-- Períodos de pausa (RF-17): um registro por ciclo, nunca sobrescrito -->
                <section aria-labelledby="sla-pauses-title" class="space-y-3">
                    <h3 id="sla-pauses-title" class="text-base font-medium text-neutral-900">Períodos de pausa</h3>

                    <p v-if="history.pauses.items.length === 0" class="rounded-md border border-neutral-200 p-3 text-sm text-neutral-600">
                        Nenhuma pausa registrada para este chamado.
                    </p>

                    <ol v-else class="space-y-3">
                        <li
                            v-for="pause in history.pauses.items"
                            :key="pause.number"
                            class="rounded-lg border p-4"
                            :class="pause.ended_at ? 'border-neutral-200' : 'border-warning-border bg-warning-bg'"
                        >
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="text-sm font-semibold text-neutral-900">Pausa {{ pause.number }}</span>
                                <StatusBadge :status="pause.type" />
                                <span v-if="!pause.ended_at" class="inline-flex items-center gap-1 text-xs font-medium text-warning-text">
                                    <Icon name="pause" :size="16" /> Em andamento
                                </span>
                            </div>
                            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                                <div>
                                    <dt class="text-xs font-medium text-neutral-500">Início</dt>
                                    <dd class="mt-0.5 text-neutral-900">
                                        <time :datetime="pause.started_at">{{ formatDateTime(pause.started_at) }}</time>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-neutral-500">Retomada</dt>
                                    <dd class="mt-0.5" :class="pause.ended_at ? 'text-neutral-900' : 'text-neutral-500'">
                                        <time v-if="pause.ended_at" :datetime="pause.ended_at">{{ formatDateTime(pause.ended_at) }}</time>
                                        <template v-else>Ainda não retomado</template>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-neutral-500">Duração</dt>
                                    <dd class="mt-0.5" :class="pause.duration_seconds !== null ? 'text-neutral-900' : 'text-neutral-500'">
                                        {{ pause.duration_seconds !== null ? formatDuration(pause.duration_seconds) : 'Calculada na retomada' }}
                                    </dd>
                                </div>
                            </dl>
                            <!-- Somente para a equipe de suporte; texto do usuário sempre como texto -->
                            <template v-if="pause.reason">
                                <p class="mt-3 text-xs font-medium text-neutral-500">Motivo</p>
                                <p class="mt-0.5 whitespace-pre-line break-words text-sm text-neutral-900">{{ pause.reason }}</p>
                            </template>
                            <dl v-if="pauseDetails(pause).length" class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                <div v-for="[label, value] in pauseDetails(pause)" :key="label" class="min-w-0">
                                    <dt class="text-xs font-medium text-neutral-500">{{ label }}</dt>
                                    <dd class="mt-0.5 break-words text-neutral-900">{{ value }}</dd>
                                </div>
                            </dl>
                        </li>
                    </ol>
                </section>

                <!-- Linha do tempo: somente eventos gravados, do mais recente ao mais antigo -->
                <section aria-labelledby="sla-events-title" class="space-y-3">
                    <h3 id="sla-events-title" class="text-base font-medium text-neutral-900">Linha do tempo</h3>

                    <EmptyState
                        v-if="history.events.length === 0"
                        icon="clock"
                        title="Nenhum evento de SLA registrado"
                        description="Pausas, retomadas, mudanças de prioridade, resoluções, reaberturas e encerramentos aparecerão aqui."
                    />

                    <Timeline v-else>
                        <TimelineItem
                            v-for="event in history.events"
                            :key="event.id"
                            :title="eventTitle[event.kind]"
                            :author="event.author ?? undefined"
                            :date="formatDateTime(event.created_at)"
                            :tone="eventTone(event)"
                        >
                            <template v-if="event.kind === 'pause_started'">
                                <template v-if="event.to">Pendência: {{ event.to }}</template>
                            </template>

                            <template v-else-if="event.kind === 'resumed'">
                                <template v-if="event.from && event.to">{{ event.from }} → {{ event.to }}</template>
                                <p v-if="event.duration_seconds !== null" class="mt-1">Pausa de {{ formatDuration(event.duration_seconds) }}.</p>
                                <p v-else-if="!event.pause_recorded" class="mt-1 text-neutral-500">Sem período de pausa registrado para esta retomada.</p>
                            </template>

                            <template v-else-if="event.kind === 'priority_changed'">
                                <template v-if="event.from && event.to">{{ event.from }} → {{ event.to }}</template>
                            </template>

                            <template v-else-if="event.kind === 'resolved'">
                                <template v-if="event.resolution_due_at">
                                    Prazo de resolução: {{ formatDateTime(event.resolution_due_at) }}
                                    <p
                                        class="mt-1 inline-flex items-center gap-1 font-medium"
                                        :class="event.on_time ? 'text-success-text' : 'text-danger-text'"
                                    >
                                        <Icon :name="event.on_time ? 'check-circle' : 'x-circle'" :size="16" />
                                        {{ event.on_time ? 'Resolvido dentro do prazo' : 'Resolvido após o prazo' }}
                                    </p>
                                </template>
                                <span v-else class="text-neutral-500">Sem prazo de resolução registrado no momento da resolução.</span>
                            </template>

                            <template v-else-if="event.kind === 'reopened'">
                                <template v-if="event.resolution_due_at || event.first_response_due_at">
                                    <p>Prazos mantidos, sem reinício do SLA:</p>
                                    <p v-if="event.first_response_due_at">Primeira resposta: {{ formatDateTime(event.first_response_due_at) }}</p>
                                    <p v-if="event.resolution_due_at">Resolução: {{ formatDateTime(event.resolution_due_at) }}</p>
                                </template>
                                <span v-else class="text-neutral-500">Sem prazos registrados no momento da reabertura.</span>
                            </template>
                        </TimelineItem>
                    </Timeline>
                </section>
            </template>
        </div>
    </Card>
</template>
