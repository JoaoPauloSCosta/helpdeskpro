<script setup lang="ts">
import { computed } from 'vue';
import Button from '@/Components/Button.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps<{
    page: number;
    perPage: number;
    total: number;
}>();

const emit = defineEmits<{ 'update:page': [page: number] }>();

const lastPage = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)));
const from = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1));
const to = computed(() => Math.min(props.page * props.perPage, props.total));

/** Janela de páginas com reticências, ex.: 1 … 4 5 [6] 7 8 … 20 */
const pages = computed<(number | null)[]>(() => {
    const last = lastPage.value;
    const current = props.page;
    const wanted = new Set([1, last, current - 1, current, current + 1]);
    const sorted = [...wanted].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);

    const result: (number | null)[] = [];
    sorted.forEach((p, i) => {
        if (i > 0 && p - sorted[i - 1] > 1) result.push(null);
        result.push(p);
    });
    return result;
});

const go = (page: number) => {
    if (page >= 1 && page <= lastPage.value && page !== props.page) emit('update:page', page);
};
</script>

<template>
    <nav
        class="flex flex-col items-center justify-between gap-3 border-t border-neutral-200 px-4 py-3 sm:flex-row"
        aria-label="Paginação"
    >
        <p class="text-sm text-neutral-600">
            Mostrando <span class="font-medium">{{ from }}</span>–<span class="font-medium">{{ to }}</span>
            de <span class="font-medium">{{ total }}</span>
        </p>
        <div class="flex items-center gap-1">
            <Button variant="secondary" size="sm" :disabled="page <= 1" @click="go(page - 1)">
                <Icon name="chevron-left" :size="16" />
                <span class="sr-only sm:not-sr-only">Anterior</span>
            </Button>
            <template v-for="(p, i) in pages" :key="i">
                <span v-if="p === null" class="px-2 text-neutral-500" aria-hidden="true">…</span>
                <Button
                    v-else
                    :variant="p === page ? 'primary' : 'ghost'"
                    size="sm"
                    :aria-current="p === page ? 'page' : undefined"
                    class="hidden min-w-8 sm:inline-flex"
                    @click="go(p)"
                >
                    {{ p }}
                </Button>
            </template>
            <Button variant="secondary" size="sm" :disabled="page >= lastPage" @click="go(page + 1)">
                <span class="sr-only sm:not-sr-only">Próxima</span>
                <Icon name="chevron-right" :size="16" />
            </Button>
        </div>
    </nav>
</template>
