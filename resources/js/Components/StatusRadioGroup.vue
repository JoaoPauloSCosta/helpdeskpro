<script setup lang="ts">
import { computed, useId } from 'vue';
import RadioField from '@/Components/RadioField.vue';
import type { Option } from '@/lib/ticket';

/** Situação ativa/inativa de um cadastro administrativo, com cada opção em um cartão clicável. */
const props = withDefaults(
    defineProps<{
        options: Option[];
        /** Explicação de cada opção, pelo valor. */
        descriptions?: Record<string, string>;
        legend?: string;
        help?: string;
        error?: string;
        required?: boolean;
    }>(),
    { legend: 'Situação', required: true },
);

const model = defineModel<string>({ required: true });

const id = useId();
const helpId = `${id}-help`;
const errorId = `${id}-error`;

const describedby = computed(
    () => [props.help && helpId, props.error && errorId].filter(Boolean).join(' ') || undefined,
);
</script>

<template>
    <fieldset :aria-describedby="describedby">
        <legend class="text-sm font-medium text-neutral-700">
            {{ legend }} <span v-if="required" class="text-danger" aria-hidden="true">*</span>
        </legend>
        <div class="mt-2 grid gap-3 sm:grid-cols-2">
            <div
                v-for="o in options"
                :key="o.value"
                class="rounded-md border p-3"
                :class="model === o.value ? 'border-primary-200 bg-primary-50' : 'border-neutral-200'"
            >
                <RadioField v-model="model" :value="o.value" :label="o.label" :description="descriptions?.[o.value]" />
            </div>
        </div>
        <p v-if="help" :id="helpId" class="mt-1 text-xs text-neutral-500">{{ help }}</p>
        <p v-if="error" :id="errorId" class="mt-1 text-sm text-danger-text">{{ error }}</p>
        <slot />
    </fieldset>
</template>
