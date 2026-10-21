<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import CommentCard from '@/Components/CommentCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PriorityBadge from '@/Components/PriorityBadge.vue';
import RadioField from '@/Components/RadioField.vue';
import SlaIndicator from '@/Components/SlaIndicator.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Textarea from '@/Components/Textarea.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import Timeline from '@/Components/Timeline.vue';
import TimelineItem from '@/Components/TimelineItem.vue';
import { useToast } from '@/composables/useToast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AssignTechnicianModal from '@/Pages/Tickets/Partials/AssignTechnicianModal.vue';
import ChangeStatusModal from '@/Pages/Tickets/Partials/ChangeStatusModal.vue';
import SelfAssignModal from '@/Pages/Tickets/Partials/SelfAssignModal.vue';
import {
    formatDateTime,
    type TicketCommentItem,
    type TicketDetail,
    type TicketHistoryItem,
    type TicketStatusChange,
    type TicketTechnician,
    type TicketSlaIndicator,
} from '@/lib/ticket';

const props = defineProps<{
    ticket: TicketDetail;
    sla: { firstResponse: TicketSlaIndicator; resolution: TicketSlaIndicator };
    comments: TicketCommentItem[];
    /** `null`: o usuário não tem acesso à auditoria (`audit.view`). */
    history: TicketHistoryItem[] | null;
    can: {
        comment: boolean;
        addInternalNote: boolean;
        update: boolean;
        assign: boolean;
        reassign: boolean;
        selfAssign: boolean;
        changeStatus: boolean;
    };
    /** `null`: o usuário não gerencia atribuições. */
    assignment: { current: TicketTechnician | null } | null;
    /** `null`: o usuário não altera status (`tickets.change_status`). */
    statusChange: TicketStatusChange | null;
    /** Recarga parcial: só existe depois que o modal de atribuição pede a lista. */
    assignableTechnicians?: TicketTechnician[];
    limits: { comment: number };
}>();

const page = usePage();
const toast = useToast();

/** Confirmação da abertura (CHA-02), com o identificador gerado pelo servidor. */
const justCreated = computed(() => page.props.flash?.ticketCreated === props.ticket.number);
/** Confirmação vinda de outra tela, como a edição (CHA-04). */
const successMessage = computed(() => page.props.flash?.success ?? null);

/* ---------- Atribuição (CHA-05) ---------- */

const assignOpen = ref(false);
/** Atribuir e reatribuir têm permissões distintas; vale a do estado atual do chamado. */
const assignmentAllowed = computed(() => (props.assignment?.current ? props.can.reassign : props.can.assign));

/* ---------- Autoatribuição (CHA-06) ---------- */

const selfAssignNumber = ref<string | null>(null);
/** Recusa vinda do servidor (chamado deixou de estar disponível). */
const selfAssignError = computed(() => (page.props.errors as Record<string, string | undefined>).self_assign);

/* ---------- Alteração de status (CHA-07) ---------- */

const statusOpen = ref(false);

/* ---------- SLA ---------- */

const slaCards = computed(() => [
    { key: 'first', title: 'Primeira resposta', metLabel: 'Respondido em', data: props.sla.firstResponse },
    { key: 'resolution', title: 'Resolução', metLabel: 'Resolvido em', data: props.sla.resolution },
]);

/* ---------- Dados do chamado ---------- */

/** [rótulo, valor, texto neutro quando o valor não existe] */
const details = computed(() => [
    ['Solicitante', props.ticket.requester, '—'],
    ['Departamento', props.ticket.department, '—'],
    ['Categoria', props.ticket.category, '—'],
    ['Responsável', props.ticket.assignee, 'Não atribuído'],
    ['Abertura', props.ticket.created_at && formatDateTime(props.ticket.created_at), '—'],
    ['Última atualização', props.ticket.updated_at && formatDateTime(props.ticket.updated_at), '—'],
]);

/* ---------- Nova mensagem ---------- */

const form = useForm({
    body: '',
    // O backend valida a visibilidade pedida; sem permissão, a opção nem aparece.
    visibility: 'public' as string | number | null,
});

const isInternal = computed(() => form.visibility === 'internal');
const focusBody = () => document.querySelector<HTMLElement>('#comment-form textarea')?.focus();
const sendError = ref(false);
let submitting = false;

const submit = () => {
    if (form.processing) return;

    sendError.value = false;
    form.clearErrors();

    if (!form.body.trim()) {
        form.setError('body', 'Escreva a mensagem antes de enviar.');
        focusBody();
        return;
    }

    submitting = true;
    form.post(route('tickets.comments.store', props.ticket.number), {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(isInternal.value ? 'Nota interna registrada.' : 'Comentário registrado.');
            form.reset('body');
        },
        onError: () => focusBody(),
        onFinish: () => (submitting = false),
    });
};

// Falha de rede ou erro do servidor no envio: o texto é mantido para nova tentativa.
const removers: (() => void)[] = [];
onMounted(() => {
    removers.push(
        router.on('exception', (event) => {
            if (!submitting) return;
            event.preventDefault();
            sendError.value = true;
        }),
        router.on('invalid', (event) => {
            if (!submitting || event.detail.response.status < 500) return;
            event.preventDefault();
            sendError.value = true;
        }),
    );
});
onUnmounted(() => removers.forEach((remove) => remove()));

const commentCount = computed(() =>
    props.comments.length === 1 ? '1 mensagem' : `${props.comments.length} mensagens`,
);
</script>

<template>
    <Head :title="`${ticket.number} · ${ticket.title}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <!-- Cabeçalho -->
            <div class="space-y-3">
                <Link
                    :href="route('tickets.index')"
                    class="inline-flex items-center gap-1 rounded-md text-sm font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                >
                    <Icon name="chevron-left" :size="16" />
                    Voltar para chamados
                </Link>

                <div class="flex flex-wrap items-center gap-2">
                    <TicketNumber :number="ticket.number" copyable />
                    <StatusBadge :status="ticket.status" />
                    <PriorityBadge :priority="ticket.priority" />
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h1 class="break-words text-2xl font-semibold text-neutral-900">{{ ticket.title }}</h1>
                        <p class="mt-1 text-sm text-neutral-600">
                            Aberto por <span class="font-medium text-neutral-900">{{ ticket.requester ?? '—' }}</span>
                            em {{ formatDateTime(ticket.created_at) }}
                        </p>
                    </div>
                    <!-- Somente ações implementadas e autorizadas; as do ciclo de vida virão com seus fluxos -->
                    <div v-if="can.update || can.assign || can.reassign || can.selfAssign || can.changeStatus" class="flex shrink-0 flex-wrap gap-2">
                        <Button v-if="can.selfAssign" @click="selfAssignNumber = ticket.number">
                            <Icon name="check" :size="16" /> Assumir chamado
                        </Button>
                        <Button v-if="can.changeStatus && statusChange" variant="secondary" @click="statusOpen = true">
                            <Icon name="chevron-right" :size="16" /> Alterar status
                        </Button>
                        <Button v-if="can.assign || can.reassign" variant="secondary" @click="assignOpen = true">
                            <Icon name="user" :size="16" /> {{ can.reassign ? 'Reatribuir técnico' : 'Atribuir técnico' }}
                        </Button>
                        <Button v-if="can.update" variant="secondary" :href="route('tickets.edit', ticket.number)">
                            <Icon name="pencil" :size="16" /> Editar chamado
                        </Button>
                    </div>
                </div>
            </div>

            <Alert v-if="successMessage" variant="success">{{ successMessage }}</Alert>
            <Alert v-if="selfAssignError" variant="warning" title="Não foi possível assumir o chamado">{{ selfAssignError }}</Alert>

            <Alert v-if="justCreated" variant="success" title="Chamado aberto com sucesso">
                <p class="flex flex-wrap items-center gap-x-1">
                    Seu chamado foi registrado com o identificador
                    <TicketNumber :number="ticket.number" />.
                </p>
            </Alert>

            <!-- SLA: somente o que o backend registrou; nada é estimado no navegador -->
            <section aria-labelledby="sla-title">
                <h2 id="sla-title" class="sr-only">Indicadores de SLA</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="card in slaCards"
                        :key="card.key"
                        class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm"
                    >
                        <p class="text-sm font-medium text-neutral-700">SLA de {{ card.title.toLowerCase() }}</p>
                        <div class="mt-2">
                            <SlaIndicator v-if="card.data.state !== 'none'" :state="card.data.state" />
                            <span v-else class="text-sm text-neutral-500">Sem prazo definido</span>
                        </div>
                        <dl v-if="card.data.due_at || card.data.met_at" class="mt-2 space-y-0.5 text-xs text-neutral-600">
                            <div v-if="card.data.due_at" class="flex gap-1">
                                <dt>Prazo:</dt>
                                <dd>{{ formatDateTime(card.data.due_at) }}</dd>
                            </div>
                            <div v-if="card.data.met_at" class="flex gap-1">
                                <dt>{{ card.metLabel }}:</dt>
                                <dd>{{ formatDateTime(card.data.met_at) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="min-w-0 space-y-6 lg:col-span-2">
                    <!-- Descrição e dados -->
                    <Card title="Descrição do chamado">
                        <!-- Interpolação de texto: o conteúdo do usuário nunca é tratado como HTML -->
                        <p class="whitespace-pre-line break-words text-sm text-neutral-900">{{ ticket.description }}</p>

                        <dl class="mt-6 grid gap-4 border-t border-neutral-200 pt-6 text-sm sm:grid-cols-2">
                            <div v-for="[label, value, fallback] in details" :key="label!" class="min-w-0">
                                <dt class="text-xs font-medium text-neutral-500">{{ label }}</dt>
                                <dd class="mt-0.5 break-words" :class="value ? 'text-neutral-900' : 'text-neutral-500'">
                                    {{ value || fallback }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-neutral-500">Prioridade</dt>
                                <dd class="mt-1"><PriorityBadge :priority="ticket.priority" /></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-neutral-500">Status</dt>
                                <dd class="mt-1"><StatusBadge :status="ticket.status" /></dd>
                            </div>
                        </dl>
                    </Card>

                    <!-- Interações -->
                    <Card>
                        <template #header>
                            <h2 class="text-lg font-semibold text-neutral-900">Interações</h2>
                            <span class="text-xs text-neutral-500">{{ commentCount }}</span>
                        </template>

                        <ol v-if="comments.length" class="space-y-3" aria-label="Mensagens do chamado">
                            <li v-for="comment in comments" :key="comment.id">
                                <CommentCard
                                    :author="comment.author ?? 'Usuário removido'"
                                    :date="formatDateTime(comment.created_at)"
                                    :body="comment.body"
                                    :internal="comment.internal"
                                />
                            </li>
                        </ol>
                        <EmptyState
                            v-else
                            icon="inbox"
                            title="Nenhuma mensagem ainda"
                            :description="can.comment ? 'Use o campo abaixo para registrar a primeira mensagem.' : undefined"
                        />

                        <template v-if="can.comment || ticket.status === 'Encerrado'" #footer>
                            <form v-if="can.comment" id="comment-form" class="space-y-4 py-2" novalidate @submit.prevent="submit">
                                <Alert v-if="sendError" variant="danger" title="Não foi possível enviar a mensagem">
                                    Verifique sua conexão e tente novamente. O texto digitado foi mantido.
                                </Alert>

                                <fieldset v-if="can.addInternalNote" class="space-y-2">
                                    <legend class="text-sm font-medium text-neutral-700">Tipo de mensagem</legend>
                                    <div class="flex flex-col gap-3 sm:flex-row sm:gap-6">
                                        <RadioField
                                            v-model="form.visibility"
                                            value="public"
                                            label="Comentário público"
                                            description="Visível ao solicitante."
                                        />
                                        <RadioField
                                            v-model="form.visibility"
                                            value="internal"
                                            label="Nota interna"
                                            description="Somente a equipe de suporte vê."
                                        />
                                    </div>
                                    <p v-if="form.errors.visibility" class="text-sm text-danger-text">{{ form.errors.visibility }}</p>
                                </fieldset>

                                <FormField
                                    :label="isInternal ? 'Nota interna' : 'Comentário'"
                                    required
                                    :error="form.errors.body"
                                    v-slot="{ id, describedby, invalid }"
                                >
                                    <Textarea
                                        :id="id"
                                        v-model="form.body"
                                        :rows="4"
                                        :placeholder="isInternal ? 'Registre uma informação para a equipe de suporte…' : 'Escreva uma mensagem…'"
                                        required
                                        :maxlength="limits.comment"
                                        :aria-describedby="describedby"
                                        :invalid="invalid"
                                        :class="isInternal && 'bg-warning-bg'"
                                        @input="form.errors.body && form.clearErrors('body')"
                                    />
                                </FormField>

                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p
                                        v-if="isInternal"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-warning-text"
                                    >
                                        <Icon name="lock" :size="16" />
                                        Esta nota não será exibida ao solicitante.
                                    </p>
                                    <span v-else class="hidden sm:block" />
                                    <Button type="submit" class="w-full sm:w-auto" :loading="form.processing">
                                        {{ form.processing ? 'Enviando…' : isInternal ? 'Adicionar nota interna' : 'Enviar comentário' }}
                                    </Button>
                                </div>
                            </form>
                            <p v-else class="py-1 text-sm text-neutral-600">
                                Este chamado foi encerrado e não recebe novas mensagens.
                            </p>
                        </template>
                    </Card>
                </div>

                <!-- Histórico: somente eventos gravados; nada é derivado do estado atual -->
                <div class="min-w-0">
                    <Card title="Histórico">
                        <EmptyState
                            v-if="history === null"
                            icon="lock"
                            title="Histórico restrito"
                            description="O histórico de alterações é exibido somente para quem tem acesso à auditoria."
                        />
                        <EmptyState
                            v-else-if="history.length === 0"
                            icon="clock"
                            title="Nenhuma alteração registrada"
                            description="Mudanças de status, responsável, prioridade, categoria e departamento aparecerão aqui."
                        />
                        <Timeline v-else>
                            <TimelineItem
                                v-for="event in history"
                                :key="event.id"
                                :title="event.title"
                                :author="event.author ?? undefined"
                                :date="formatDateTime(event.created_at)"
                            >
                                <template v-if="event.from && event.to">{{ event.from }} → {{ event.to }}</template>
                                <template v-else-if="event.to">{{ event.to }}</template>
                            </TimelineItem>
                        </Timeline>
                    </Card>
                </div>
            </div>
        </div>

        <AssignTechnicianModal
            v-if="assignment"
            :show="assignOpen"
            :ticket="ticket"
            :current="assignment.current"
            :technicians="assignableTechnicians"
            :allowed="assignmentAllowed"
            @close="assignOpen = false"
        />
        <ChangeStatusModal
            v-if="statusChange"
            :show="statusOpen"
            :ticket="ticket"
            :status-change="statusChange"
            @close="statusOpen = false"
        />
        <SelfAssignModal :number="selfAssignNumber" @close="selfAssignNumber = null" />
    </AuthenticatedLayout>
</template>
