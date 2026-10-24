<script setup lang="ts">
import { computed, ref } from 'vue';
import Icon from '@/Components/Icon.vue';
import { useToast } from '@/composables/useToast';
import { formatSize, screenFiles, type AttachmentLimits } from '@/lib/attachments';

const props = defineProps<{
    modelValue: File[];
    limits: AttachmentLimits;
    /** Mensagens do servidor para o campo de anexos. */
    errors?: string[];
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [files: File[]] }>();

const toast = useToast();
const input = ref<HTMLInputElement | null>(null);

const accept = computed(() => props.limits.extensions.map((extension) => `.${extension}`).join(','));
const hint = computed(
    () =>
        `Até ${props.limits.maxFiles} arquivos de ${formatSize(props.limits.maxFileBytes)} cada. ` +
        `Tipos: ${props.limits.extensions.join(', ').toUpperCase()}.`,
);

const onPick = (event: Event) => {
    const element = event.target as HTMLInputElement;
    const { accepted, rejected } = screenFiles(props.modelValue, Array.from(element.files ?? []), props.limits);

    // Permite escolher o mesmo arquivo de novo depois de removê-lo.
    element.value = '';

    if (accepted.length) emit('update:modelValue', [...props.modelValue, ...accepted]);
    rejected.forEach((message) => toast.error(message));
};

const remove = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));
</script>

<template>
    <div>
        <p id="attachments-label" class="text-sm font-medium text-neutral-700">Anexos <span class="font-normal text-neutral-500">(opcional)</span></p>

        <div class="mt-1 rounded-md border border-dashed border-neutral-300 bg-neutral-50 p-3">
            <input
                ref="input"
                type="file"
                multiple
                class="sr-only"
                :accept="accept"
                :disabled="disabled"
                aria-labelledby="attachments-label"
                aria-describedby="attachments-hint"
                @change="onPick"
            />
            <button
                type="button"
                class="inline-flex h-10 items-center gap-2 rounded-md border border-neutral-300 bg-white px-3 text-sm font-medium text-neutral-700 hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="disabled || modelValue.length >= limits.maxFiles"
                @click="input?.click()"
            >
                <Icon name="plus" :size="16" /> Anexar arquivos
            </button>
            <p id="attachments-hint" class="mt-2 text-xs text-neutral-500">{{ hint }}</p>

            <ul v-if="modelValue.length" class="mt-3 space-y-2" aria-label="Arquivos selecionados">
                <li
                    v-for="(file, index) in modelValue"
                    :key="`${file.name}-${file.size}-${index}`"
                    class="flex items-center justify-between gap-3 rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm"
                >
                    <span class="min-w-0 break-words text-neutral-900">
                        {{ file.name }} <span class="text-xs text-neutral-500">· {{ formatSize(file.size) }}</span>
                    </span>
                    <button
                        type="button"
                        class="shrink-0 rounded text-xs font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        :disabled="disabled"
                        :aria-label="`Remover ${file.name}`"
                        @click="remove(index)"
                    >
                        Remover
                    </button>
                </li>
            </ul>
        </div>

        <p v-for="message in errors" :key="message" class="mt-1 text-sm text-danger-text" role="alert">{{ message }}</p>
    </div>
</template>
