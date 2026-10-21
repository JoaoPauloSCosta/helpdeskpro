<script setup lang="ts">
import { computed } from 'vue';
import Icon, { type IconName } from '@/Components/Icon.vue';

const props = withDefaults(
    defineProps<{
        variant?: 'success' | 'warning' | 'danger' | 'info';
        title?: string;
    }>(),
    { variant: 'info' },
);

const config: Record<string, { box: string; icon: IconName }> = {
    success: { box: 'border-success-border bg-success-bg text-success-text', icon: 'check-circle' },
    warning: { box: 'border-warning-border bg-warning-bg text-warning-text', icon: 'warning' },
    danger: { box: 'border-danger-border bg-danger-bg text-danger-text', icon: 'x-circle' },
    info: { box: 'border-primary-200 bg-primary-50 text-primary-800', icon: 'info' },
};

const current = computed(() => config[props.variant]);
</script>

<template>
    <div
        class="flex gap-3 rounded-md border p-4 text-sm"
        :class="current.box"
        :role="variant === 'danger' ? 'alert' : 'status'"
    >
        <Icon :name="current.icon" />
        <div class="min-w-0 flex-1">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div :class="title && 'mt-1'"><slot /></div>
        </div>
    </div>
</template>
