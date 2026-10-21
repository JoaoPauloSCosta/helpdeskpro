<script setup lang="ts">
import { useId } from 'vue';
import Radio from '@/Components/Radio.vue';

defineProps<{
    label: string;
    value: string | number;
    description?: string;
}>();

const model = defineModel<string | number | null>({ required: true });
const id = useId();
</script>

<template>
    <div class="flex items-start gap-3">
        <Radio :id="id" v-model="model" :value="value" class="mt-0.5" />
        <label :for="id" class="text-sm">
            <span class="font-medium text-neutral-700">{{ label }}</span>
            <span v-if="description" class="block text-xs text-neutral-500">{{ description }}</span>
        </label>
    </div>
</template>
