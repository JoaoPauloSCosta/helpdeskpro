<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Icon, { type IconName } from '@/Components/Icon.vue';

defineProps<{
    label: string;
    value: string | number;
    hint?: string;
    /** Ícone discreto ao lado do rótulo; o significado continua no texto. */
    icon?: IconName;
    /** Destino com o detalhamento do indicador (ex.: a listagem filtrada). */
    href?: string;
    /** Texto da chamada para o detalhamento, no rodapé do card. */
    linkLabel?: string;
}>();
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        class="block rounded-lg border border-neutral-200 bg-white p-4 shadow-sm sm:p-6"
        :class="
            href &&
            'transition-colors duration-150 ease-in-out hover:border-primary-200 hover:bg-primary-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2'
        "
    >
        <p class="flex items-center gap-2 text-sm text-neutral-600">
            <Icon v-if="icon" :name="icon" :size="16" class="shrink-0 text-neutral-400" />
            {{ label }}
        </p>
        <p class="mt-1 text-3xl font-semibold tabular-nums text-neutral-900">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-neutral-500">{{ hint }}</p>
        <p v-if="href" class="mt-3 flex items-center gap-1 text-xs font-medium text-primary-600">
            {{ linkLabel ?? 'Ver chamados' }}
            <Icon name="chevron-right" :size="16" />
        </p>
    </component>
</template>
