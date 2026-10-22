<script setup lang="ts" generic="T extends { value: string; label: string; total: number }">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Distribuição em barras horizontais (design-system.md, 6.9). Cada linha mostra o rótulo, a
 * quantidade e a participação no total em texto; a barra é só um reforço visual, oculto dos
 * leitores de tela, então a lista também serve como a versão tabular do gráfico.
 */
const props = defineProps<{
    items: T[];
    /** Base das porcentagens: o total de chamados considerados. */
    total: number;
    /** Destino de cada linha (ex.: a listagem filtrada); sem destino, a linha não é um link. */
    href?: (item: T) => string | null;
    /** Descrição do link para leitores de tela (ex.: "Ver chamados com status Aberto"). */
    linkLabel?: (item: T) => string;
    emptyText?: string;
}>();

defineSlots<{ label?(props: { item: T }): unknown }>();

const max = computed(() => Math.max(0, ...props.items.map((item) => item.total)));

/** Comprimento relativo ao maior valor, a partir da base zero. */
const width = (total: number) => (max.value ? `${(total / max.value) * 100}%` : '0%');

const share = (total: number) => {
    if (!props.total || !total) return '0%';
    const percent = (total / props.total) * 100;

    return percent < 1 ? '<1%' : `${Math.round(percent).toLocaleString('pt-BR')}%`;
};

const rows = computed(() => props.items.map((item) => ({ item, href: props.href?.(item) ?? null })));
</script>

<template>
    <p v-if="items.length === 0" class="py-6 text-center text-sm text-neutral-500">
        {{ emptyText ?? 'Sem dados para exibir.' }}
    </p>
    <ul v-else class="space-y-1">
        <li v-for="{ item, href } in rows" :key="item.value">
            <component
                :is="href ? Link : 'div'"
                :href="href ?? undefined"
                class="block rounded-md px-2 py-2"
                :class="
                    href &&
                    'transition-colors duration-150 ease-in-out hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500'
                "
            >
                <span class="flex items-center justify-between gap-3 text-sm">
                    <span class="min-w-0 truncate text-neutral-900">
                        <slot name="label" :item="item">{{ item.label }}</slot>
                    </span>
                    <span class="shrink-0 tabular-nums">
                        <span class="font-semibold text-neutral-900">{{ item.total.toLocaleString('pt-BR') }}</span>
                        <span class="ms-1 text-xs text-neutral-500">({{ share(item.total) }})</span>
                    </span>
                </span>
                <span class="mt-1.5 block h-2 rounded-full bg-neutral-100" aria-hidden="true">
                    <span
                        class="block h-2 rounded-full bg-primary-500"
                        :style="{ width: width(item.total), minWidth: item.total ? '4px' : undefined }"
                    />
                </span>
                <span v-if="href && linkLabel" class="sr-only">{{ linkLabel(item) }}</span>
            </component>
        </li>
    </ul>
</template>
