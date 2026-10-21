<script setup lang="ts">
import { inputClasses } from '@/lib/ui';

withDefaults(
    defineProps<{
        invalid?: boolean;
        rows?: number;
    }>(),
    { rows: 4 },
);

const model = defineModel<string>({ required: true });
</script>

<template>
    <textarea
        :rows="rows"
        :class="inputClasses(invalid)"
        :aria-invalid="invalid || undefined"
        v-model="model"
    />
</template>
