<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    hint?: string;
}>();
</script>

<template>
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm sm:p-6">
        <p class="text-sm text-neutral-600">{{ label }}</p>
        <p class="mt-1 text-3xl font-semibold text-neutral-900">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-neutral-500">{{ hint }}</p>
    </div>
</template>
