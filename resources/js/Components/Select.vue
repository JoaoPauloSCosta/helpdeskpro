<script setup lang="ts">
import { inputClasses } from '@/lib/ui';

defineProps<{
    invalid?: boolean;
    placeholder?: string;
}>();

const model = defineModel<string | number | null>({ required: true });
</script>

<template>
    <select
        class="h-10"
        :class="inputClasses(invalid)"
        :aria-invalid="invalid || undefined"
        v-model="model"
    >
        <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
        <slot />
    </select>
</template>
