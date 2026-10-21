<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import RadioField from '@/Components/RadioField.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Spinner from '@/Components/Spinner.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import type { TicketDetail, TicketTechnician } from '@/lib/ticket';

const props = defineProps<{
    show: boolean;
    ticket: TicketDetail;
    current: TicketTechnician | null;
    /** `undefined` enquanto a lista não foi carregada do servidor. */
    technicians: TicketTechnician[] | undefined;
    /** Se o usuário pode executar a operação no estado atual (atribuir ou reatribuir). */
    allowed: boolean;
}>();

const emit = defineEmits<{ close: [] }>();

const isReassign = computed(() => props.current !== null);
const title = computed(() => (isReassign.value ? 'Reatribuir técnico' : 'Atribuir técnico'));

const form = useForm({
    technician_id: null as string | number | null,
    // Responsável visto ao abrir o modal; o servidor recusa se ele mudou nesse meio-tempo.
    expected_assignee_id: null as number | null,
});

const search = ref('');
const loading = ref(false);
const loadError = ref(false);
const sendError = ref(false);
let submitting = false;

const conflict = computed(() => (form.errors as Record<string, string | undefined>).conflict);
const formError = computed(() => (form.errors as Record<string, string | undefined>).expected_assignee_id);

/** Busca o que estiver na lista agora: elegíveis e sem o responsável atual. */
const loadTechnicians = () => {
    loading.value = true;
    loadError.value = false;
    router.reload({
        only: ['assignableTechnicians'],
        onFinish: () => {
            loading.value = false;
            loadError.value = props.technicians === undefined;
        },
    });
};

/** Prepara uma nova tentativa a partir do responsável exibido agora. */
const startFresh = () => {
    form.technician_id = null;
    form.expected_assignee_id = props.current?.id ?? null;
    search.value = '';
    loadTechnicians();
};

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.clearErrors();
        sendError.value = false;
        startFresh();
    },
);

const normalize = (value: string) =>
    value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const filtered = computed(() => {
    const term = normalize(search.value.trim());
    const list = props.technicians ?? [];
    return term
        ? list.filter((t) => normalize(`${t.name} ${t.department ?? ''}`).includes(term))
        : list;
});

const selected = computed(() => props.technicians?.find((t) => t.id === form.technician_id) ?? null);

const submit = () => {
    if (form.processing || !selected.value || !props.allowed) return;

    sendError.value = false;
    submitting = true;
    form.put(route('tickets.assignee.update', props.ticket.number), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (errors) => {
            // A resposta de erro recarrega a página sem a lista; no conflito, o responsável já veio atualizado.
            if (errors.conflict) startFresh();
            else loadTechnicians();
        },
        onFinish: () => (submitting = false),
    });
};

const close = () => {
    if (!form.processing) emit('close');
};

// Falha de rede ou do servidor no envio: o modal continua aberto para nova tentativa.
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
</script>

<template>
    <Modal :show="show" max-width="lg" :closeable="!form.processing" @close="close">
        <form class="flex max-h-[90vh] flex-col" novalidate @submit.prevent="submit">
            <div class="space-y-4 overflow-y-auto p-4 sm:p-6">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">{{ title }}</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <TicketNumber :number="ticket.number" />
                        <StatusBadge :status="ticket.status" />
                    </div>
                    <p class="mt-1 break-words text-sm text-neutral-900">{{ ticket.title }}</p>
                    <p v-if="ticket.requester" class="text-xs text-neutral-500">Solicitante: {{ ticket.requester }}</p>
                </div>

                <Alert v-if="conflict" variant="warning" title="O chamado foi alterado">{{ conflict }}</Alert>
                <Alert v-if="!allowed" variant="info">
                    Você não tem permissão para {{ isReassign ? 'reatribuir' : 'atribuir' }} este chamado no estado atual.
                </Alert>
                <Alert v-if="formError" variant="danger">{{ formError }}</Alert>
                <Alert v-if="sendError" variant="danger" title="Não foi possível concluir">
                    Verifique sua conexão e tente novamente.
                </Alert>

                <!-- Responsável atual -->
                <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3">
                    <p class="text-xs font-medium text-neutral-500">Responsável atual</p>
                    <p v-if="current" class="text-sm font-medium text-neutral-900">
                        {{ current.name }}
                        <span v-if="current.department" class="font-normal text-neutral-600">· {{ current.department }}</span>
                    </p>
                    <p v-else class="text-sm text-neutral-600">Nenhum técnico atribuído</p>
                </div>

                <!-- Novo responsável -->
                <fieldset class="space-y-3" :aria-busy="loading">
                    <legend class="text-sm font-medium text-neutral-700">
                        Novo responsável <span class="text-danger" aria-hidden="true">*</span>
                    </legend>

                    <div v-if="loading" class="flex items-center gap-2 py-6 text-sm text-neutral-600" role="status">
                        <Spinner /> Carregando técnicos…
                    </div>

                    <Alert v-else-if="loadError" variant="danger" title="Não foi possível carregar os técnicos">
                        <Button variant="secondary" size="sm" class="mt-2" @click="loadTechnicians">Tentar novamente</Button>
                    </Alert>

                    <EmptyState
                        v-else-if="technicians && technicians.length === 0"
                        icon="users"
                        title="Nenhum técnico elegível"
                        description="Não há outros técnicos ativos que possam receber este chamado."
                    />

                    <template v-else-if="technicians">
                        <SearchInput v-model="search" placeholder="Buscar por nome ou departamento" aria-label="Buscar técnico" />

                        <p v-if="filtered.length === 0" class="py-4 text-center text-sm text-neutral-600" role="status">
                            Nenhum técnico corresponde à busca.
                        </p>
                        <div v-else class="max-h-64 space-y-1 overflow-y-auto rounded-md border border-neutral-200 p-2">
                            <div
                                v-for="technician in filtered"
                                :key="technician.id"
                                class="rounded-md px-2 py-2 transition-colors duration-150 hover:bg-neutral-50"
                                :class="form.technician_id === technician.id && 'bg-primary-50 hover:bg-primary-50'"
                            >
                                <RadioField
                                    v-model="form.technician_id"
                                    :value="technician.id"
                                    :label="technician.name"
                                    :description="technician.department ?? 'Sem departamento'"
                                    @update:model-value="form.clearErrors('technician_id')"
                                />
                            </div>
                        </div>
                    </template>

                    <p v-if="form.errors.technician_id" class="text-sm text-danger-text" role="alert">
                        {{ form.errors.technician_id }}
                    </p>
                </fieldset>

                <p class="text-xs text-neutral-500">A atribuição não altera o status do chamado.</p>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="close">Cancelar</Button>
                <Button type="submit" class="w-full sm:w-auto" :loading="form.processing" :disabled="!selected || loading || !allowed">
                    {{ form.processing ? 'Salvando…' : isReassign ? 'Confirmar reatribuição' : 'Confirmar atribuição' }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
