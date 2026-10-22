<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

/** Trilha de navegação; o último item é a página atual e não tem link. */
defineProps<{
    items: { label: string; href?: string | null }[];
}>();
</script>

<template>
    <nav aria-label="Trilha de navegação">
        <ol class="flex flex-wrap items-center gap-1 text-sm text-neutral-600">
            <template v-for="(item, index) in items" :key="index">
                <li v-if="index > 0" aria-hidden="true"><Icon name="chevron-right" :size="16" class="text-neutral-400" /></li>
                <li v-if="index === items.length - 1" aria-current="page" class="font-medium text-neutral-900">
                    {{ item.label }}
                </li>
                <li v-else>
                    <Link
                        v-if="item.href"
                        :href="item.href"
                        class="rounded hover:text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    >
                        {{ item.label }}
                    </Link>
                    <span v-else>{{ item.label }}</span>
                </li>
            </template>
        </ol>
    </nav>
</template>
