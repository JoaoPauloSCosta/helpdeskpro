<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import Textarea from '@/Components/Textarea.vue';

/**
 * Modal com justificativa obrigatória: reabertura, pendência, resolução (resumo da solução).
 * O botão fica desabilitado até o texto ser preenchido; a validação definitiva é do backend.
 */
const props = withDefaults(
    defineProps<{
        show: boolean;
        title: string;
        description?: string;
        label?: string;
        confirmLabel?: string;
        variant?: 'primary' | 'danger';
        processing?: boolean;
        error?: string;
    }>(),
    { label: 'Justificativa', confirmLabel: 'Confirmar', variant: 'primary' },
);

const emit = defineEmits<{ close: []; confirm: [text: string] }>();

const text = ref('');
const canConfirm = computed(() => text.value.trim().length > 0);

watch(
    () => props.show,
    (show) => {
        if (show) text.value = '';
    },
);

const submit = () => {
    if (canConfirm.value) emit('confirm', text.value.trim());
};
</script>

<template>
    <Modal :show="show" max-width="lg" :closeable="!processing" @close="$emit('close')">
        <form class="p-6" @submit.prevent="submit">
            <h2 class="text-lg font-semibold text-neutral-900">{{ title }}</h2>
            <p v-if="description" class="mt-2 text-sm text-neutral-600">{{ description }}</p>

            <div class="mt-4">
                <FormField :label="label" :error="error" required v-slot="{ id, describedby, invalid }">
                    <Textarea
                        :id="id"
                        v-model="text"
                        :aria-describedby="describedby"
                        :invalid="invalid"
                        :rows="4"
                    />
                </FormField>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <Button variant="secondary" :disabled="processing" @click="$emit('close')">
                    Cancelar
                </Button>
                <Button type="submit" :variant="variant" :loading="processing" :disabled="!canConfirm">
                    {{ confirmLabel }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
