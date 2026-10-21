<script setup lang="ts">
import { toneClasses, type Tone } from '@/lib/ui';

withDefaults(defineProps<{ tone?: Tone }>(), { tone: 'neutral' });
</script>

<template>
    <span
        class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium"
        :class="toneClasses[tone]"
    >
        <slot />
    </span>
</template>
