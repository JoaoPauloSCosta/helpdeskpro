<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Textarea from '@/Components/Textarea.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import type { TicketDetail, TicketResolutionForm } from '@/lib/ticket';
import { useToast } from '@/composables/useToast';

/**
 * Resolução (CHA-09). O backend revalida permissão, status atual e resumo ao gravar; autor,
 * instante da resolução e status final são definidos por ele.
 */
const props = defineProps<{
    show: boolean;
    ticket: TicketDetail;
    resolution: TicketResolutionForm;
    /** Se o chamado, no estado exibido agora, admite a resolução por este usuário. */
    allowed: boolean;
}>();

const emit = defineEmits<{ close: [] }>();

const toast = useToast();
const form = useForm({
    resolution_summary: '',
    // Status visto ao abrir o modal; o servidor recusa se ele mudou nesse meio-tempo.
    expected_status: '',
});

let submitting = false;

const errors = computed(() => form.errors as Record<string, string | undefined>);
const filled = computed(() => form.resolution_summary.trim().length > 0);
const canSubmit = computed(() => props.allowed && filled.value);

const focusSummary = () => nextTick(() => document.querySelector<HTMLElement>('#resolve-form textarea')?.focus());

// Cancelar descarta o preenchimento: cada abertura começa do zero.
watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        form.expected_status = props.resolution.current;
    },
);

const submit = () => {
    if (form.processing) return;


    if (!filled.value) {
        form.setError('resolution_summary', 'Descreva a solução aplicada.');
        focusSummary();
        return;
    }

    if (!props.allowed) return;

    submitting = true;
    form.post(route('tickets.resolution.store', props.ticket.number), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        // No conflito, a página já voltou com o status atualizado; o texto digitado é mantido.
        onError: (errs) => {
            if (errs.conflict) form.expected_status = props.resolution.current;
            else if (errs.resolution_summary) focusSummary();
        },
        onFinish: () => (submitting = false),
    });
};

const close = () => {
    if (!form.processing) emit('close');
};

// Falha de rede ou do servidor no envio: o modal continua aberto, com o texto, para nova tentativa.
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
    <!-- Esc e clique fora não descartam um resumo já digitado; "Cancelar" continua disponível -->
    <Modal :show="show" max-width="lg" :closeable="!form.processing && !filled" @close="close">
        <form id="resolve-form" class="flex max-h-[90vh] flex-col" novalidate @submit.prevent="submit">
            <div class="space-y-4 overflow-y-auto p-4 sm:p-6">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Resolver chamado</h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        Registre o resultado do atendimento. O resumo fica visível nos detalhes do chamado, inclusive para o solicitante.
                    </p>
                </div>

                <!-- Contexto do chamado -->
                <div class="space-y-2 rounded-md border border-neutral-200 bg-neutral-50 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <TicketNumber :number="ticket.number" />
                    </div>
                    <p class="break-words text-sm text-neutral-900">{{ ticket.title }}</p>
                    <p class="inline-flex flex-wrap items-center gap-1 text-xs text-neutral-600">
                        <span class="sr-only">Status atual:</span>
                        <StatusBadge :status="ticket.status" />
                        <span aria-hidden="true">→</span>
                        <span class="sr-only">novo status:</span>
                        <StatusBadge status="Resolvido" />
                    </p>
                </div>

                <Alert v-if="errors.conflict" variant="warning" title="O chamado foi alterado">{{ errors.conflict }}</Alert>
                <Alert v-else-if="!allowed" variant="warning" title="Resolução indisponível">
                    Este chamado não pode ser resolvido por você no estado atual.
                </Alert>
                <FormField
                    label="Resumo da solução"
                    required
                    :error="errors.resolution_summary"
                    help="Descreva o problema identificado, a solução aplicada e o resultado obtido."
                    v-slot="{ id, describedby, invalid }"
                >
                    <Textarea
                        :id="id"
                        v-model="form.resolution_summary"
                        :rows="6"
                        required
                        :maxlength="resolution.limits.summary"
                        :aria-describedby="describedby"
                        :invalid="invalid"
                        @input="errors.resolution_summary && form.clearErrors('resolution_summary')"
                    />
                </FormField>

                <p class="text-xs text-neutral-500">
                    Resolver não encerra o chamado: o encerramento é uma etapa separada.
                    O SLA de resolução é apurado com o instante registrado pelo servidor.
                </p>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="close">Cancelar</Button>
                <Button type="submit" class="w-full sm:w-auto" :loading="form.processing" :disabled="!canSubmit">
                    {{ form.processing ? 'Resolvendo…' : 'Confirmar resolução' }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
