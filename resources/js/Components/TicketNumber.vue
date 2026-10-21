<script setup lang="ts">
import Icon from '@/Components/Icon.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps<{
    number: string;
    copyable?: boolean;
}>();

const toast = useToast();

const copy = async () => {
    try {
        await navigator.clipboard.writeText(props.number);
        toast.success(`${props.number} copiado.`);
    } catch {
        toast.error('Não foi possível copiar o identificador.');
    }
};
</script>

<template>
    <span class="inline-flex items-center gap-1.5 font-mono text-sm font-medium text-neutral-900">
        {{ number }}
        <button
            v-if="copyable"
            type="button"
            class="rounded p-0.5 text-neutral-400 hover:text-neutral-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            aria-label="Copiar identificador"
            @click="copy"
        >
            <Icon name="copy" :size="16" />
        </button>
    </span>
</template>
