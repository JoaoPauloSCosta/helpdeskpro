<script setup lang="ts">
import Icon from '@/Components/Icon.vue';

withDefaults(
    defineProps<{
        /** `light`: sobre o painel azul; `default`: sobre fundo branco. */
        tone?: 'default' | 'light';
    }>(),
    { tone: 'default' },
);
</script>

<template>
    <div class="flex items-center gap-3">
        <span
            class="grid shrink-0 place-items-center rounded-lg border"
            :class="
                tone === 'light'
                    ? 'h-11 w-11 border-white/75 bg-white/15 text-white shadow-md'
                    : 'h-9 w-9 border-primary-100 bg-primary-50 text-primary-600'
            "
            aria-hidden="true"
        >
            <Icon name="headset" :size="tone === 'light' ? 24 : 20" />
        </span>
        <span
            class="font-extrabold leading-none tracking-tight"
            :class="tone === 'light' ? 'text-2xl text-white' : 'text-xl text-neutral-900'"
        >
            Help Desk Pro
        </span>
    </div>
</template>
