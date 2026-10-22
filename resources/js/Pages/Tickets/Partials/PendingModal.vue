<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import Select from '@/Components/Select.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import Textarea from '@/Components/Textarea.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import type { TicketDetail, TicketPendingField, TicketPendingRegistration } from '@/lib/ticket';

/**
 * Registro de pendência (CHA-08). Tipos e campos vêm do backend, que revalida permissão, tipo,
 * status atual e dados ao gravar; autor, início da pausa e efeito no SLA são definidos por ele.
 */
const props = defineProps<{
    show: boolean;
    ticket: TicketDetail;
    pending: TicketPendingRegistration;
}>();

const emit = defineEmits<{ close: [] }>();

const textFields: { key: Exclude<TicketPendingField, 'related_department_id' | 'expected_return_at'>; label: string; help?: string }[] = [
    { key: 'supplier_name', label: 'Fornecedor ou terceiro' },
    { key: 'protocol_number', label: 'Protocolo externo', help: 'Número do chamado ou protocolo aberto junto ao fornecedor ou à área.' },
    { key: 'responsible_name', label: 'Responsável externo ou contato' },
];

const form = useForm({
    pause_status: '' as string | number | null,
    // Status visto ao abrir o modal; o servidor recusa se ele mudou nesse meio-tempo.
    expected_status: '',
    reason: '',
    supplier_name: '',
    protocol_number: '',
    related_department_id: '' as string | number | null,
    responsible_name: '',
    expected_return_at: '',
});

const sendError = ref(false);
let submitting = false;

const errors = computed(() => form.errors as Record<string, string | undefined>);
const selected = computed(() => props.pending.types.find((t) => t.value === form.pause_status) ?? null);
const accepts = (field: TicketPendingField) => selected.value?.fields.includes(field) ?? false;
const canSubmit = computed(() => !!selected.value && form.reason.trim().length > 0);

/** Data local de hoje (AAAA-MM-DD): a previsão de retorno não pode estar no passado. */
const today = computed(() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
});

// Cancelar descarta o preenchimento: cada abertura começa do zero.
watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        sendError.value = false;
        form.expected_status = props.pending.current;
    },
);

const submit = () => {
    if (form.processing || !canSubmit.value) return;

    sendError.value = false;
    submitting = true;
    form.transform((data) => {
        // Somente os campos do tipo escolhido; os demais nem são enviados.
        const fields = selected.value?.fields ?? [];
        const details = Object.fromEntries(fields.map((field) => [field, data[field] || null]));

        return {
            pause_status: data.pause_status,
            expected_status: data.expected_status,
            reason: data.reason,
            ...details,
        };
    }).post(route('tickets.pending.store', props.ticket.number), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        // No conflito, a página já voltou com o status atualizado. O texto digitado é mantido, mas a
        // nova tentativa parte do status exibido agora e só mantém o tipo se ele ainda for permitido.
        onError: (errs) => {
            if (!errs.conflict) return;
            form.expected_status = props.pending.current;
            if (!selected.value) form.pause_status = '';
        },
        onFinish: () => (submitting = false),
    });
};

const close = () => {
    if (!form.processing) emit('close');
};

// Falha de rede ou do servidor no envio: o modal continua aberto, com os dados, para nova tentativa.
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
                    <h2 class="text-lg font-semibold text-neutral-900">Registrar pendência</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <TicketNumber :number="ticket.number" />
                        <StatusBadge :status="ticket.status" />
                    </div>
                    <p class="mt-1 break-words text-sm text-neutral-900">{{ ticket.title }}</p>
                </div>

                <Alert v-if="errors.conflict" variant="warning" title="O chamado foi alterado">{{ errors.conflict }}</Alert>
                <Alert v-if="errors.expected_status" variant="danger">{{ errors.expected_status }}</Alert>
                <Alert v-if="sendError" variant="danger" title="Não foi possível concluir">
                    Verifique sua conexão e tente novamente. Os dados preenchidos foram mantidos.
                </Alert>

                <EmptyState
                    v-if="pending.types.length === 0"
                    icon="lock"
                    title="Nenhuma pendência disponível"
                    description="Pendências só podem ser registradas em chamados em atendimento."
                />

                <template v-else>
                    <FormField label="Tipo de pendência" required :error="errors.pause_status" v-slot="{ id, describedby, invalid }">
                        <Select
                            :id="id"
                            v-model="form.pause_status"
                            placeholder="Selecione o tipo de pendência"
                            :aria-describedby="describedby"
                            :invalid="invalid"
                            @change="form.clearErrors('pause_status')"
                        >
                            <option v-for="type in pending.types" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </option>
                        </Select>
                    </FormField>

                    <p v-if="selected?.description" class="-mt-2 text-xs text-neutral-600">{{ selected.description }}</p>

                    <FormField
                        label="Motivo da pendência"
                        required
                        :error="errors.reason"
                        help="Explique por que o atendimento não pode prosseguir agora."
                        v-slot="{ id, describedby, invalid }"
                    >
                        <Textarea
                            :id="id"
                            v-model="form.reason"
                            :rows="4"
                            required
                            :maxlength="pending.limits.reason"
                            :aria-describedby="describedby"
                            :invalid="invalid"
                            @input="errors.reason && form.clearErrors('reason')"
                        />
                    </FormField>

                    <!-- Campos específicos do tipo escolhido (RF-17), todos opcionais -->
                    <div v-if="selected && selected.fields.length" class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            v-if="accepts('related_department_id')"
                            class="sm:col-span-2"
                            label="Departamento aguardado"
                            :error="errors.related_department_id"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <Select
                                :id="id"
                                v-model="form.related_department_id"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @change="form.clearErrors('related_department_id')"
                            >
                                <option value="">Não informado</option>
                                <option v-for="department in pending.departments" :key="department.value" :value="department.value">
                                    {{ department.label }}
                                </option>
                            </Select>
                        </FormField>

                        <template v-for="field in textFields" :key="field.key">
                            <FormField
                                v-if="accepts(field.key)"
                                :label="field.label"
                                :help="field.help"
                                :error="errors[field.key]"
                                v-slot="{ id, describedby, invalid }"
                            >
                                <TextInput
                                    :id="id"
                                    v-model="form[field.key]"
                                    :maxlength="pending.limits.text"
                                    :aria-describedby="describedby"
                                    :invalid="invalid"
                                    @input="errors[field.key] && form.clearErrors(field.key)"
                                />
                            </FormField>
                        </template>

                        <FormField
                            v-if="accepts('expected_return_at')"
                            label="Previsão de retorno"
                            :error="errors.expected_return_at"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.expected_return_at"
                                type="date"
                                :min="today"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @input="errors.expected_return_at && form.clearErrors('expected_return_at')"
                            />
                        </FormField>
                    </div>

                    <Alert v-if="selected" variant="info">
                        <span class="inline-flex flex-wrap items-center gap-1">
                            <StatusBadge :status="ticket.status" /> → <StatusBadge :status="selected.label" />
                        </span>
                        <p class="mt-2 inline-flex items-start gap-1">
                            <Icon name="pause" :size="16" class="mt-0.5 shrink-0" />
                            O SLA de resolução fica pausado a partir do registro, até a retomada do atendimento.
                            O SLA de primeira resposta não é afetado.
                        </p>
                    </Alert>
                </template>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="close">Cancelar</Button>
                <Button type="submit" class="w-full sm:w-auto" :loading="form.processing" :disabled="!canSubmit">
                    {{ form.processing ? 'Registrando…' : 'Registrar pendência' }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
