<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import type { TicketCloseForm, TicketDetail, TicketResolution } from '@/lib/ticket';
import { useToast } from '@/composables/useToast';

/**
 * Encerramento (CHA-11). O backend revalida permissão, acesso e status atual ao gravar; autor,
 * instante do encerramento e status final são definidos por ele. A solução não é pedida de novo:
 * ela pertence à resolução (CHA-09) e é apenas exibida.
 */
const props = defineProps<{
    show: boolean;
    ticket: TicketDetail;
    closing: TicketCloseForm;
    /** Solução registrada, quando disponível. */
    resolution: TicketResolution | null;
    /** Se o chamado, no estado exibido agora, admite o encerramento por este usuário. */
    allowed: boolean;
}>();

const emit = defineEmits<{ close: [] }>();

const toast = useToast();
const form = useForm({
    // Status visto ao abrir o modal; o servidor recusa se ele mudou nesse meio-tempo.
    expected_status: '',
});

let submitting = false;

const errors = computed(() => form.errors as Record<string, string | undefined>);

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.clearErrors();
        form.expected_status = props.closing.current;
    },
);

const submit = () => {
    if (form.processing || !props.allowed) return;

    submitting = true;
    form.post(route('tickets.closing.store', props.ticket.number), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        // No conflito, a página já voltou com o status atualizado e o modal mostra o motivo.
        onError: (errs) => {
            if (errs.conflict) form.expected_status = props.closing.current;
        },
        onFinish: () => (submitting = false),
    });
};

const dismiss = () => {
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
    <Modal :show="show" max-width="lg" :closeable="!form.processing" @close="dismiss">
        <form id="close-form" class="flex max-h-[90vh] flex-col" novalidate @submit.prevent="submit">
            <div class="space-y-4 overflow-y-auto p-4 sm:p-6">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Encerrar chamado</h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        O encerramento conclui o atendimento. Pelo fluxo normal, um chamado encerrado não pode ser reaberto.
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
                        <StatusBadge status="Encerrado" />
                    </p>
                </div>

                <!-- Solução registrada na resolução (CHA-09), somente leitura e sempre como texto -->
                <div v-if="resolution">
                    <p class="text-xs font-medium text-neutral-500">Solução registrada</p>
                    <p class="mt-0.5 max-h-40 overflow-y-auto whitespace-pre-line break-words text-sm text-neutral-900">{{ resolution.summary }}</p>
                </div>

                <Alert v-if="errors.conflict" variant="warning" title="O chamado foi alterado">{{ errors.conflict }}</Alert>
                <Alert v-else-if="!allowed" variant="warning" title="Encerramento indisponível">
                    Este chamado não pode ser encerrado por você no estado atual.
                </Alert>
                <Alert variant="info">
                    O status será alterado de Resolvido para Encerrado. A solução e os comentários serão preservados.
                </Alert>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="dismiss">Cancelar</Button>
                <!-- Variante de perigo: o design system reserva-a para ações irreversíveis como encerrar (6.1) -->
                <Button type="submit" variant="danger" class="w-full sm:w-auto" :loading="form.processing" :disabled="!allowed">
                    <Icon v-if="!form.processing" name="lock" :size="16" />
                    {{ form.processing ? 'Encerrando…' : 'Confirmar encerramento' }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
