<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { inputClasses } from '@/lib/ui';

defineProps<{
    invalid?: boolean;
}>();

const model = defineModel<string>({ required: true });

const input = ref<HTMLInputElement | null>(null);

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value?.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        class="h-10"
        :class="inputClasses(invalid)"
        :aria-invalid="invalid || undefined"
        v-model="model"
        ref="input"
    />
</template>
