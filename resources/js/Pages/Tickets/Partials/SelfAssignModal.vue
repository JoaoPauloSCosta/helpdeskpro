<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { useToast } from '@/composables/useToast';

/**
 * Confirmação da autoatribuição (CHA-06), usada pela listagem e pelos detalhes. A regra fica
 * toda no backend: aqui só se envia o identificador do chamado.
 */
const props = defineProps<{
    /** Chamado a assumir; `null` mantém o modal fechado. */
    number: string | null;
}>();

const emit = defineEmits<{ close: [] }>();

const toast = useToast();
const processing = ref(false);

const confirm = () => {
    if (!props.number || processing.value) return;

    router.post(
        route('tickets.self-assignment.store', props.number),
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => {
                processing.value = false;
                emit('close');
            },
        },
    );
};

// Falha de rede ou do servidor: nenhuma mudança é presumida.
const removers: (() => void)[] = [];
onMounted(() => {
    removers.push(
        router.on('exception', (event) => {
            if (!processing.value) return;
            event.preventDefault();
            toast.error('Não foi possível assumir o chamado. Tente novamente.');
        }),
        router.on('invalid', (event) => {
            if (!processing.value || event.detail.response.status < 500) return;
            event.preventDefault();
            toast.error('Não foi possível assumir o chamado. Tente novamente.');
        }),
    );
});
onUnmounted(() => removers.forEach((remove) => remove()));
</script>

<template>
    <ConfirmModal
        :show="number !== null"
        title="Assumir este chamado?"
        message="Você será definido como responsável pelo atendimento e o status passará para Em atendimento."
        confirm-label="Confirmar e assumir"
        :processing="processing"
        @close="emit('close')"
        @confirm="confirm"
    />
</template>
