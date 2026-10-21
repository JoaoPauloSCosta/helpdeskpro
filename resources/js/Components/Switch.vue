<script setup lang="ts">
defineProps<{
    label: string;
    disabled?: boolean;
}>();

const model = defineModel<boolean>({ required: true });
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="model"
        :disabled="disabled"
        class="inline-flex items-center gap-3 rounded-md text-sm text-neutral-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        @click="model = !model"
    >
        <span
            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-150"
            :class="model ? 'bg-primary-500' : 'bg-neutral-300'"
        >
            <span
                class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform duration-150"
                :class="model ? 'translate-x-5' : 'translate-x-0.5'"
            />
        </span>
        {{ label }}
    </button>
</template>
