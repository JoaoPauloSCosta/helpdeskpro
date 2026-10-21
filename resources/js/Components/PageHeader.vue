<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
}>();
</script>

<template>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold text-neutral-900">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-neutral-600">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.default" class="flex shrink-0 flex-wrap items-center gap-3">
            <slot />
        </div>
    </div>
</template>
