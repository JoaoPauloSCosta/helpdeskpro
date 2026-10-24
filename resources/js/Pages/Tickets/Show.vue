<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import AttachmentList from '@/Components/AttachmentList.vue';
import AttachmentPicker from '@/Components/AttachmentPicker.vue';
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
import { useToast } from '@/composables/useToast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AssignTechnicianModal from '@/Pages/Tickets/Partials/AssignTechnicianModal.vue';
import AuditHistory from '@/Pages/Tickets/Partials/AuditHistory.vue';
import ChangeStatusModal from '@/Pages/Tickets/Partials/ChangeStatusModal.vue';
import CloseModal from '@/Pages/Tickets/Partials/CloseModal.vue';
import CommentHistoryModal from '@/Pages/Tickets/Partials/CommentHistoryModal.vue';
import PendingModal from '@/Pages/Tickets/Partials/PendingModal.vue';
import ReopenModal from '@/Pages/Tickets/Partials/ReopenModal.vue';
import ResolveModal from '@/Pages/Tickets/Partials/ResolveModal.vue';
import SelfAssignModal from '@/Pages/Tickets/Partials/SelfAssignModal.vue';
import SlaHistory from '@/Pages/Tickets/Partials/SlaHistory.vue';
import {
    formatDate,
    formatDateTime,
    slaBadgeLabel,
    slaDetailText,
    type TicketActivePending,
    type TicketCloseForm,
    type TicketClosure,
    type TicketCommentItem,
    type TicketDetail,
    type TicketPendingRegistration,
    type TicketReopenForm,
    type TicketReopening,
    type TicketResolution,
    type TicketResolutionForm,
    type TicketStatusChange,
    type TicketTechnician,
    type TicketSlaIndicator,
} from '@/lib/ticket';
import { formatTarget } from '@/lib/sla';
import type { AttachmentLimits, TicketAttachment } from '@/lib/attachments';

const props = defineProps<{
    ticket: TicketDetail;
    /** `null` na visão restrita do solicitante (RF-24). */
    sla: { firstResponse: TicketSlaIndicator; resolution: TicketSlaIndicator } | null;
    comments: (TicketCommentItem & { attachments: TicketAttachment[] })[];
    /** Anexos da abertura (públicos). */
    attachments: TicketAttachment[];
    attachmentLimits: AttachmentLimits;
    can: {
        comment: boolean;
        addInternalNote: boolean;
        update: boolean;
        assign: boolean;
        reassign: boolean;
        selfAssign: boolean;
        changeStatus: boolean;
        registerPending: boolean;
        resolve: boolean;
        reopen: boolean;
        close: boolean;
        /** `audit.view` e acesso ao chamado (CHA-14); o histórico é carregado sob demanda. */
        viewAudit: boolean;
    };
    /** `null`: o usuário não gerencia atribuições. */
    assignment: { current: TicketTechnician | null } | null;
    /** `null`: o usuário não altera status (`tickets.change_status`). */
    statusChange: TicketStatusChange | null;
    /** `null`: o usuário não registra pendências (`tickets.change_status`). */
    pending: TicketPendingRegistration | null;
    /** `null`: sem pendência em andamento ou usuário fora da equipe de suporte. */
    activePending: TicketActivePending | null;
    /** `null`: o usuário não resolve chamados (`tickets.resolve`). */
    resolutionForm: TicketResolutionForm | null;
    /** `null`: o chamado não está resolvido nem encerrado. */
    resolution: TicketResolution | null;
    /** `null`: o usuário não é o solicitante original (CHA-10). */
    reopenForm: TicketReopenForm | null;
    /** `null`: o chamado não foi reaberto ou voltou a ser resolvido ou encerrado. */
    reopening: TicketReopening | null;
    /** `null`: o usuário não encerra chamados (`tickets.close`). */
    closeForm: TicketCloseForm | null;
    /** `null`: o chamado não está encerrado. */
    closure: TicketClosure | null;
    /** Recarga parcial: só existe depois que o modal de atribuição pede a lista. */
    assignableTechnicians?: TicketTechnician[];
    limits: { comment: number };
}>();

const page = usePage();
const toast = useToast();

/**
 * Confirmações da abertura (CHA-02, com o identificador gerado pelo servidor) e das
 * alterações no chamado. Cada resposta traz um novo `flash`, então ações repetidas
 * voltam a notificar; recargas parciais mantêm o objeto e não repetem o toast.
 */
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.ticketCreated === props.ticket.number) {
            toast.success(`Chamado ${props.ticket.number} aberto com sucesso.`);
        }
    },
    { immediate: true },
);

/* ---------- Atribuição (CHA-05) ---------- */

const assignOpen = ref(false);
/** Atribuir e reatribuir têm permissões distintas; vale a do estado atual do chamado. */
const assignmentAllowed = computed(() => (props.assignment?.current ? props.can.reassign : props.can.assign));

/* ---------- Autoatribuição (CHA-06) ---------- */

const selfAssignNumber = ref<string | null>(null);
/** Recusa vinda do servidor (chamado deixou de estar disponível). */

/* ---------- Alteração de status (CHA-07) ---------- */

const statusOpen = ref(false);

/* ---------- Registro de pendência (CHA-08) ---------- */

const pendingOpen = ref(false);

/* ---------- Resolução (CHA-09) ---------- */

const resolveOpen = ref(false);

/* ---------- Reabertura (CHA-10) ---------- */

const reopenOpen = ref(false);

/* ---------- Encerramento (CHA-11) ---------- */

const closeOpen = ref(false);

/* ---------- Histórico de comentários (CHA-12) ---------- */

/** Mensagem cujo histórico está aberto; mantida durante a animação de fechamento. */
const historyComment = ref<TicketCommentItem | null>(null);
const historyOpen = ref(false);
const openHistory = (comment: TicketCommentItem) => {
    historyComment.value = comment;
    historyOpen.value = true;
};

/** [rótulo, valor] dos dados registrados da pendência em andamento; vazios são omitidos. */
const pendingDetails = computed(() => {
    const p = props.activePending;
    if (!p) return [];
    return (
        [
            ['Fornecedor ou terceiro', p.supplier_name],
            ['Departamento aguardado', p.related_department],
            ['Protocolo externo', p.protocol_number],
            ['Responsável externo ou contato', p.responsible_name],
            ['Previsão de retorno', p.expected_return_at && formatDate(p.expected_return_at)],
            ['Registrada por', p.author],
            ['Início da pausa', formatDateTime(p.started_at)],
        ] as [string, string | null][]
    ).filter(([, value]) => value);
});

/* ---------- SLA ---------- */

const slaCards = computed(() =>
    props.sla
        ? [
              { key: 'first', title: 'Primeira resposta', metLabel: 'Respondido em', data: props.sla.firstResponse },
              { key: 'resolution', title: 'Resolução', metLabel: 'Resolvido em', data: props.sla.resolution },
          ]
        : [],
);

/* ---------- Dados do chamado ---------- */

/** [rótulo, valor, texto neutro quando o valor não existe] */
const details = computed(() => [
    // O solicitante não vê o campo Solicitante (RF-24): o backend nem o envia.
    ...(props.ticket.requester !== undefined ? [['Solicitante', props.ticket.requester, '—']] : []),
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
    attachments: [] as File[],
});

/** Erros do servidor para os anexos: do campo e de cada arquivo (`attachments.0`, ...). */
const attachmentErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key === 'attachments' || key.startsWith('attachments.'))
        .map(([, message]) => message),
);

const isInternal = computed(() => form.visibility === 'internal');
const focusBody = () => document.querySelector<HTMLElement>('#comment-form textarea')?.focus();
let submitting = false;

const submit = () => {
    if (form.processing) return;

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
            form.reset('body', 'attachments');
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
            toast.unexpected();
        }),
        router.on('invalid', (event) => {
            if (!submitting || event.detail.response.status < 500) return;
            event.preventDefault();
            toast.unexpected();
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
                    <PriorityBadge v-if="ticket.priority" :priority="ticket.priority" />
                </div>

                <div class="space-y-4">
                    <div class="min-w-0">
                        <h1 class="break-words text-2xl font-semibold text-neutral-900">{{ ticket.title }}</h1>
                        <p class="mt-1 text-sm text-neutral-600">
                            <template v-if="ticket.requester !== undefined">
                                Aberto por <span class="font-medium text-neutral-900">{{ ticket.requester ?? '—' }}</span>
                                em {{ formatDateTime(ticket.created_at) }}
                            </template>
                            <template v-else>Aberto em {{ formatDateTime(ticket.created_at) }}</template>
                        </p>
                    </div>
                    <!-- Ações autorizadas, logo abaixo do título e acima do cartão do chamado -->
                    <div v-if="can.update || can.assign || can.reassign || can.selfAssign || can.changeStatus || can.registerPending || can.resolve || can.reopen || can.close" class="flex flex-wrap gap-2">
                        <Button v-if="can.selfAssign" @click="selfAssignNumber = ticket.number">
                            <Icon name="check" :size="16" /> Assumir chamado
                        </Button>
                        <Button v-if="can.resolve && resolutionForm" @click="resolveOpen = true">
                            <Icon name="check-circle" :size="16" /> Resolver chamado
                        </Button>
                        <Button v-if="can.reopen && reopenForm" @click="reopenOpen = true">
                            <Icon name="arrow-path" :size="16" /> Reabrir chamado
                        </Button>
                        <Button v-if="can.close && closeForm" @click="closeOpen = true">
                            <Icon name="lock" :size="16" /> Encerrar chamado
                        </Button>
                        <Button v-if="can.changeStatus && statusChange" variant="secondary" @click="statusOpen = true">
                            <Icon name="chevron-right" :size="16" /> Alterar status
                        </Button>
                        <Button v-if="can.registerPending && pending" variant="secondary" @click="pendingOpen = true">
                            <Icon name="pause" :size="16" /> Registrar pendência
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

            <!-- SLA: somente o que o backend registrou; nada é estimado no navegador -->
            <section v-if="sla" aria-labelledby="sla-title" class="space-y-4">
                <h2 id="sla-title" class="sr-only">Indicadores de SLA</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="card in slaCards"
                        :key="card.key"
                        class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm"
                    >
                        <p class="text-sm font-medium text-neutral-700">SLA de {{ card.title.toLowerCase() }}</p>
                        <div class="mt-2">
                            <SlaIndicator
                                v-if="card.data.state !== 'none'"
                                :state="card.data.state"
                                :label="slaBadgeLabel(card.data)"
                            />
                            <span v-else class="text-sm text-neutral-500">Sem prazo definido</span>
                        </div>
                        <p v-if="slaDetailText(card.data)" class="mt-1 text-xs text-neutral-600">{{ slaDetailText(card.data) }}</p>
                        <dl
                            v-if="card.data.due_at || card.data.met_at || card.data.target_minutes"
                            class="mt-2 space-y-0.5 text-xs text-neutral-600"
                        >
                            <div v-if="card.data.target_minutes" class="flex gap-1">
                                <dt>Meta:</dt>
                                <dd>{{ formatTarget(card.data.target_minutes) }}</dd>
                            </div>
                            <div v-if="card.data.due_at" class="flex gap-1">
                                <dt>Prazo:</dt>
                                <dd>{{ formatDateTime(card.data.due_at) }}</dd>
                            </div>
                            <div v-if="card.data.met_at" class="flex gap-1">
                                <dt>{{ card.metLabel }}:</dt>
                                <dd>{{ formatDateTime(card.data.met_at) }}</dd>
                            </div>
                        </dl>
                        <!-- Histórico de violação (RN-15): não some quando uma meta mais longa devolve o chamado ao prazo -->
                        <p v-if="card.data.breached_at" class="mt-2 flex items-center gap-1 text-xs font-medium text-danger-text">
                            <Icon name="x-circle" :size="16" /> Violação registrada em {{ formatDateTime(card.data.breached_at) }}
                        </p>
                    </div>
                </div>

                <!-- Histórico de SLA (CHA-13): carregado sob demanda, somente leitura -->
                <SlaHistory :ticket="ticket" />
            </section>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="min-w-0 space-y-6 lg:col-span-2">
                    <!-- Descrição e dados -->
                    <Card title="Descrição do chamado">
                        <!-- Interpolação de texto: o conteúdo do usuário nunca é tratado como HTML -->
                        <p class="whitespace-pre-line break-words text-sm text-neutral-900">{{ ticket.description }}</p>
                        <AttachmentList :ticket="ticket.number" :attachments="attachments" />

                        <dl class="mt-6 grid gap-4 border-t border-neutral-200 pt-6 text-sm sm:grid-cols-2">
                            <div v-for="[label, value, fallback] in details" :key="label!" class="min-w-0">
                                <dt class="text-xs font-medium text-neutral-500">{{ label }}</dt>
                                <dd class="mt-0.5 break-words" :class="value ? 'text-neutral-900' : 'text-neutral-500'">
                                    {{ value || fallback }}
                                </dd>
                            </div>
                            <div v-if="ticket.priority">
                                <dt class="text-xs font-medium text-neutral-500">Prioridade</dt>
                                <dd class="mt-1"><PriorityBadge :priority="ticket.priority" /></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-neutral-500">Status</dt>
                                <dd class="mt-1"><StatusBadge :status="ticket.status" /></dd>
                            </div>
                        </dl>
                    </Card>

                    <!-- Solução registrada (RF-12): texto gravado pelo backend, sempre como texto -->
                    <Card v-if="resolution">
                        <template #header>
                            <h2 class="text-lg font-semibold text-neutral-900">Solução</h2>
                            <StatusBadge status="Resolvido" />
                        </template>
                        <p class="whitespace-pre-line break-words text-sm text-neutral-900">{{ resolution.summary }}</p>
                        <p class="mt-4 border-t border-neutral-200 pt-4 text-xs text-neutral-600">
                            Resolvido<template v-if="resolution.author"> por <span class="font-medium text-neutral-900">{{ resolution.author }}</span></template>
                            em {{ formatDateTime(resolution.resolved_at) }}
                        </p>
                        <!-- Encerramento (RF-14): instante e autor gravados pelo backend -->
                        <p v-if="closure" class="mt-1 text-xs text-neutral-600">
                            Encerrado<template v-if="closure.author"> por <span class="font-medium text-neutral-900">{{ closure.author }}</span></template>
                            em {{ formatDateTime(closure.closed_at) }}
                        </p>
                    </Card>

                    <!-- Reabertura (RF-13): justificativa gravada pelo backend, sempre como texto -->
                    <Card v-if="reopening">
                        <template #header>
                            <h2 class="text-lg font-semibold text-neutral-900">Reabertura</h2>
                        </template>
                        <p class="text-xs font-medium text-neutral-500">Justificativa</p>
                        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-neutral-900">{{ reopening.justification }}</p>
                        <p class="mt-4 border-t border-neutral-200 pt-4 text-xs text-neutral-600">
                            Reaberto<template v-if="reopening.author"> por <span class="font-medium text-neutral-900">{{ reopening.author }}</span></template>
                            em {{ formatDateTime(reopening.reopened_at) }}
                        </p>
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
                                    :edited="comment.edited"
                                    can-view-history
                                    @show-history="openHistory(comment)"
                                >
                                    <template #attachments>
                                        <AttachmentList :ticket="ticket.number" :attachments="comment.attachments" />
                                    </template>
                                </CommentCard>
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

                                <AttachmentPicker
                                    v-model="form.attachments"
                                    :limits="attachmentLimits"
                                    :errors="attachmentErrors"
                                    :disabled="form.processing"
                                />

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

                <!-- Pendência em andamento e histórico de auditoria -->
                <div class="min-w-0 space-y-6">
                    <!-- Pendência em andamento (RF-17): dados gravados pelo backend, só para a equipe -->
                    <Card v-if="activePending">
                        <template #header>
                            <h2 class="text-lg font-semibold text-neutral-900">Pendência em andamento</h2>
                            <StatusBadge :status="activePending.type" />
                        </template>
                        <p class="text-xs font-medium text-neutral-500">Motivo</p>
                        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-neutral-900">{{ activePending.reason }}</p>
                        <dl class="mt-4 space-y-3 border-t border-neutral-200 pt-4 text-sm">
                            <div v-for="[label, value] in pendingDetails" :key="label" class="min-w-0">
                                <dt class="text-xs font-medium text-neutral-500">{{ label }}</dt>
                                <dd class="mt-0.5 break-words text-neutral-900">{{ value }}</dd>
                            </div>
                        </dl>
                    </Card>

                    <!-- Histórico de auditoria (CHA-14): somente eventos gravados, carregado sob demanda -->
                    <!-- Sem `audit.view` o cartão não existe na tela: nem placeholder, nem consulta (RN-20) -->
                    <AuditHistory v-if="can.viewAudit" :ticket="ticket" :allowed="can.viewAudit" />
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
        <PendingModal
            v-if="pending"
            :show="pendingOpen"
            :ticket="ticket"
            :pending="pending"
            @close="pendingOpen = false"
        />
        <ResolveModal
            v-if="resolutionForm"
            :show="resolveOpen"
            :ticket="ticket"
            :resolution="resolutionForm"
            :allowed="can.resolve"
            @close="resolveOpen = false"
        />
        <ReopenModal
            v-if="reopenForm"
            :show="reopenOpen"
            :ticket="ticket"
            :reopen="reopenForm"
            :allowed="can.reopen"
            @close="reopenOpen = false"
        />
        <CloseModal
            v-if="closeForm"
            :show="closeOpen"
            :ticket="ticket"
            :closing="closeForm"
            :resolution="resolution"
            :allowed="can.close"
            @close="closeOpen = false"
        />
        <CommentHistoryModal
            :show="historyOpen"
            :ticket="ticket"
            :comment="historyComment"
            @close="historyOpen = false"
        />
        <SelfAssignModal :number="selfAssignNumber" @close="selfAssignNumber = null" />
    </AuthenticatedLayout>
</template>
