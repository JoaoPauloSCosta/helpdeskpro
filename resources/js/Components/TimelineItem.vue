<script setup lang="ts">
withDefaults(
    defineProps<{
        title: string;
        author?: string;
        /** Data e hora já formatadas para exibição. */
        date: string;
        /** primary: evento do chamado · success: resolução · warning: pausa de SLA · danger: violação de SLA · neutral: administrativo */
        tone?: 'primary' | 'success' | 'warning' | 'danger' | 'neutral';
    }>(),
    { tone: 'primary' },
);

const dot = {
    primary: 'bg-primary-500',
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger',
    neutral: 'bg-neutral-400',
};
</script>

<template>
    <li class="relative pb-6 ps-8 last:pb-0">
        <span class="timeline-line absolute bottom-0 start-[7px] top-4 w-px bg-neutral-200" aria-hidden="true" />
        <span
            class="absolute start-0 top-1 h-4 w-4 rounded-full border-2 border-white ring-1 ring-neutral-200"
            :class="dot[tone]"
            aria-hidden="true"
        />
        <p class="text-sm font-medium text-neutral-900">{{ title }}</p>
        <p class="text-xs text-neutral-500">
            <template v-if="author">{{ author }} · </template>{{ date }}
        </p>
        <div v-if="$slots.default" class="mt-1 text-sm text-neutral-600"><slot /></div>
    </li>
</template>
