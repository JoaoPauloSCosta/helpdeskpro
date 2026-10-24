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
import type { TicketDetail, TicketReopenForm } from '@/lib/ticket';
import { useToast } from '@/composables/useToast';

/**
 * Reabertura (CHA-10). O backend revalida a propriedade do chamado, o status atual e a
 * justificativa ao gravar; autor, instante da reabertura e novo status são definidos por ele.
 */
const props = defineProps<{
    show: boolean;
    ticket: TicketDetail;
    reopen: TicketReopenForm;
    /** Se o chamado, no estado exibido agora, admite a reabertura por este usuário. */
    allowed: boolean;
}>();

const emit = defineEmits<{ close: [] }>();

const toast = useToast();
const form = useForm({
    justification: '',
    // Status visto ao abrir o modal; o servidor recusa se ele mudou nesse meio-tempo.
    expected_status: '',
});

let submitting = false;

const errors = computed(() => form.errors as Record<string, string | undefined>);
const filled = computed(() => form.justification.trim().length > 0);
const canSubmit = computed(() => props.allowed && filled.value);

const focusJustification = () =>
    nextTick(() => document.querySelector<HTMLElement>('#reopen-form textarea')?.focus());

// Cancelar descarta o preenchimento: cada abertura começa do zero.
watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        form.expected_status = props.reopen.current;
    },
);

const submit = () => {
    if (form.processing) return;


    if (!filled.value) {
        form.setError('justification', 'Explique o motivo da reabertura.');
        focusJustification();
        return;
    }

    if (!props.allowed) return;

    submitting = true;
    form.post(route('tickets.reopening.store', props.ticket.number), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        // No conflito, a página já voltou com o status atualizado; o texto digitado é mantido.
        onError: (errs) => {
            if (errs.conflict) form.expected_status = props.reopen.current;
            else if (errs.justification) focusJustification();
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
    <!-- Esc e clique fora não descartam uma justificativa já digitada; "Cancelar" continua disponível -->
    <Modal :show="show" max-width="lg" :closeable="!form.processing && !filled" @close="close">
        <form id="reopen-form" class="flex max-h-[90vh] flex-col" novalidate @submit.prevent="submit">
            <div class="space-y-4 overflow-y-auto p-4 sm:p-6">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Reabrir chamado</h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        Se o problema não foi resolvido ou voltou a ocorrer, explique o motivo da reabertura.
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
                        <StatusBadge status="Aberto" />
                    </p>
                </div>

                <Alert v-if="errors.conflict" variant="warning" title="O chamado foi alterado">{{ errors.conflict }}</Alert>
                <Alert v-else-if="!allowed" variant="warning" title="Reabertura indisponível">
                    O status atual deste chamado não permite a reabertura.
                </Alert>
                <FormField
                    label="Justificativa da reabertura"
                    required
                    :error="errors.justification"
                    help="Explique por que o problema não foi resolvido ou voltou a ocorrer."
                    v-slot="{ id, describedby, invalid }"
                >
                    <Textarea
                        :id="id"
                        v-model="form.justification"
                        :rows="5"
                        required
                        :maxlength="reopen.limits.justification"
                        :aria-describedby="describedby"
                        :invalid="invalid"
                        @input="errors.justification && form.clearErrors('justification')"
                    />
                </FormField>

                <Alert variant="info">
                    O chamado voltará para Aberto. A solução e os comentários anteriores serão preservados.
                </Alert>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="close">Cancelar</Button>
                <Button type="submit" class="w-full sm:w-auto" :loading="form.processing" :disabled="!canSubmit">
                    {{ form.processing ? 'Reabrindo…' : 'Confirmar reabertura' }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
