<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import Select from '@/Components/Select.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import type { TicketDetail, TicketStatusChange } from '@/lib/ticket';
import { useToast } from '@/composables/useToast';

/**
 * Alteração de status (CHA-07). Os destinos vêm do backend, que revalida permissão, transição
 * e status atual ao gravar; nenhuma mudança é presumida antes da resposta.
 */
const props = defineProps<{
    show: boolean;
    ticket: TicketDetail;
    statusChange: TicketStatusChange;
}>();

const emit = defineEmits<{ close: [] }>();

const toast = useToast();
const form = useForm({
    status: '' as string | number | null,
    // Status visto ao abrir o modal; o servidor recusa se ele mudou nesse meio-tempo.
    expected_status: '',
});

let submitting = false;

const conflict = computed(() => (form.errors as Record<string, string | undefined>).conflict);
const selected = computed(() => props.statusChange.targets.find((t) => t.value === form.status) ?? null);

/** Prepara uma nova tentativa a partir do status exibido agora. */
const startFresh = () => {
    form.status = '';
    form.expected_status = props.statusChange.current;
};

// Cancelar descarta a escolha: cada abertura começa do zero.
watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.clearErrors();
        startFresh();
    },
);

const submit = () => {
    if (form.processing || !selected.value) return;

    submitting = true;
    form.put(route('tickets.status.update', props.ticket.number), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        // No conflito, a página já voltou com o status atualizado; a escolha anterior não vale mais.
        onError: (errors) => {
            if (errors.conflict) startFresh();
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
</script>

<template>
    <Modal :show="show" max-width="lg" :closeable="!form.processing" @close="close">
        <form class="flex max-h-[90vh] flex-col" novalidate @submit.prevent="submit">
            <div class="space-y-4 overflow-y-auto p-4 sm:p-6">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Alterar status</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <TicketNumber :number="ticket.number" />
                    </div>
                    <p class="mt-1 break-words text-sm text-neutral-900">{{ ticket.title }}</p>
                </div>

                <Alert v-if="conflict" variant="warning" title="O chamado foi alterado">{{ conflict }}</Alert>
                <!-- Status atual -->
                <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3">
                    <p class="text-xs font-medium text-neutral-500">Status atual</p>
                    <div class="mt-1"><StatusBadge :status="ticket.status" /></div>
                </div>

                <EmptyState
                    v-if="statusChange.targets.length === 0"
                    icon="lock"
                    title="Nenhuma alteração disponível"
                    description="Não há alteração de status que você possa executar no estado atual deste chamado."
                />

                <template v-else>
                    <FormField label="Novo status" required :error="form.errors.status" v-slot="{ id, describedby, invalid }">
                        <Select
                            :id="id"
                            v-model="form.status"
                            placeholder="Selecione o novo status"
                            :aria-describedby="describedby"
                            :invalid="invalid"
                            @change="form.clearErrors('status')"
                        >
                            <option v-for="target in statusChange.targets" :key="target.value" :value="target.value">
                                {{ target.label }}
                            </option>
                        </Select>
                    </FormField>

                    <Alert v-if="selected?.description" variant="info">
                        <span class="inline-flex flex-wrap items-center gap-1">
                            <StatusBadge :status="ticket.status" /> → <StatusBadge :status="selected.label" />
                        </span>
                        <p class="mt-2">{{ selected.description }}</p>
                    </Alert>
                </template>

                <p class="text-xs text-neutral-500">
                    Pendências, resolução, reabertura e encerramento possuem fluxos próprios e não aparecem nesta lista.
                </p>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="close">Cancelar</Button>
                <Button type="submit" class="w-full sm:w-auto" :loading="form.processing" :disabled="!selected">
                    {{ form.processing ? 'Salvando…' : 'Confirmar alteração' }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
