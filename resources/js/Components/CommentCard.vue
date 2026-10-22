<script setup lang="ts">
import Icon from '@/Components/Icon.vue';

defineProps<{
    author: string;
    /** Data já formatada para exibição. */
    date: string;
    body: string;
    /** Nota interna: nunca é exibida ao solicitante (RF-11). */
    internal?: boolean;
    edited?: boolean;
    /** Exibe o acesso ao histórico de versões, apenas para quem tem permissão. */
    canViewHistory?: boolean;
}>();

defineEmits<{ 'show-history': [] }>();
</script>

<template>
    <article
        class="rounded-lg border p-4"
        :class="internal ? 'border-warning-border bg-warning-bg' : 'border-neutral-200 bg-white'"
    >
        <header class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="text-sm font-semibold text-neutral-900">{{ author }}</span>
            <time class="text-xs text-neutral-500">{{ date }}</time>
            <span
                v-if="internal"
                class="inline-flex items-center gap-1 rounded-full bg-white/60 px-2 py-0.5 text-xs font-medium text-warning-text"
            >
                <Icon name="lock" :size="16" />
                Nota interna
            </span>
            <template v-if="edited">
                <button
                    v-if="canViewHistory"
                    type="button"
                    aria-haspopup="dialog"
                    class="rounded text-xs text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    @click="$emit('show-history')"
                >
                    (editado) · ver versões anteriores
                </button>
                <span v-else class="text-xs text-neutral-500">(editado)</span>
            </template>
        </header>
        <p class="mt-2 whitespace-pre-line text-sm text-neutral-900">{{ body }}</p>
        <p v-if="internal" class="mt-2 text-xs text-warning-text">Não visível ao solicitante.</p>
    </article>
</template>
