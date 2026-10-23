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
        <img
            v-if="tone === 'default'"
            src="/images/logo-icon.png"
            alt=""
            class="h-9 w-9 shrink-0 rounded-lg"
        />
        <span
            v-else
            class="grid h-11 w-11 shrink-0 place-items-center rounded-lg border border-white/75 bg-white/15 text-white shadow-md"
            aria-hidden="true"
        >
            <Icon name="headset" :size="24" />
        </span>
        <span
            class="font-extrabold leading-none tracking-tight"
            :class="tone === 'light' ? 'text-2xl text-white' : 'text-xl text-neutral-900'"
        >
            Help Desk Pro
        </span>
    </div>
</template>
