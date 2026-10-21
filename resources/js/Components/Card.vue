<script setup lang="ts">
defineProps<{
    title?: string;
    /** Remove o padding interno (tabelas e listas que vão de borda a borda). */
    flush?: boolean;
}>();
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm">
        <header
            v-if="title || $slots.header"
            class="flex items-center justify-between gap-4 border-b border-neutral-200 px-4 py-4 sm:px-6"
        >
            <slot name="header">
                <h2 class="text-lg font-semibold text-neutral-900">{{ title }}</h2>
            </slot>
        </header>
        <div :class="!flush && 'p-4 sm:p-6'">
            <slot />
        </div>
        <footer
            v-if="$slots.footer"
            class="border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:px-6"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
