<script setup lang="ts">
import Button from '@/Components/Button.vue';
import Modal from '@/Components/Modal.vue';

withDefaults(
    defineProps<{
        show: boolean;
        title: string;
        message: string;
        confirmLabel?: string;
        cancelLabel?: string;
        variant?: 'primary' | 'danger';
        processing?: boolean;
    }>(),
    { confirmLabel: 'Confirmar', cancelLabel: 'Cancelar', variant: 'primary' },
);

defineEmits<{ close: []; confirm: [] }>();
</script>

<template>
    <Modal :show="show" max-width="md" :closeable="!processing" @close="$emit('close')">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-neutral-900">{{ title }}</h2>
            <p class="mt-2 text-sm text-neutral-600">{{ message }}</p>
            <div class="mt-6 flex justify-end gap-3">
                <Button variant="secondary" :disabled="processing" @click="$emit('close')">
                    {{ cancelLabel }}
                </Button>
                <Button :variant="variant" :loading="processing" @click="$emit('confirm')">
                    {{ confirmLabel }}
                </Button>
            </div>
        </div>
    </Modal>
</template>
