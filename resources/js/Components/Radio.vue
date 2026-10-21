<script setup lang="ts">
defineProps<{
    value: string | number;
}>();

const model = defineModel<string | number | null>({ required: true });
</script>

<template>
    <input
        type="radio"
        :value="value"
        v-model="model"
        class="border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500"
    />
</template>
