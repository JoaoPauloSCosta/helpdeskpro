<script setup lang="ts">
import { useId } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';

defineProps<{
    label: string;
    description?: string;
}>();

const checked = defineModel<boolean>({ required: true });
const id = useId();
</script>

<template>
    <div class="flex items-start gap-3">
        <Checkbox :id="id" v-model:checked="checked" class="mt-0.5" />
        <label :for="id" class="text-sm">
            <span class="font-medium text-neutral-700">{{ label }}</span>
            <span v-if="description" class="block text-xs text-neutral-500">{{ description }}</span>
        </label>
    </div>
</template>
