<script setup lang="ts">
import Icon, { type IconName } from '@/Components/Icon.vue';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: IconName;
    }>(),
    { icon: 'inbox' },
);
</script>

<template>
    <div class="flex flex-col items-center px-4 py-12 text-center">
        <Icon :name="icon" :size="24" class="text-neutral-400" />
        <h3 class="mt-3 text-base font-medium text-neutral-900">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-neutral-600">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
