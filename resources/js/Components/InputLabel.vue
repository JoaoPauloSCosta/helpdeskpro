<script setup lang="ts">
defineProps<{
    value?: string;
    required?: boolean;
}>();
</script>

<template>
    <label class="block text-sm font-medium text-neutral-700">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
        <span v-if="required" class="ms-0.5 text-danger" aria-hidden="true">*</span>
    </label>
</template>
