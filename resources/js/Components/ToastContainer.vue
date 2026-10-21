<script setup lang="ts">
import Icon, { type IconName } from '@/Components/Icon.vue';
import { useToast, type ToastVariant } from '@/composables/useToast';

const { toasts, dismiss } = useToast();

const styles: Record<ToastVariant, { box: string; icon: IconName }> = {
    success: { box: 'border-success-border', icon: 'check-circle' },
    info: { box: 'border-primary-200', icon: 'info' },
    warning: { box: 'border-warning-border', icon: 'warning' },
    danger: { box: 'border-danger-border', icon: 'x-circle' },
};

const iconColor: Record<ToastVariant, string> = {
    success: 'text-success',
    info: 'text-primary-500',
    warning: 'text-warning',
    danger: 'text-danger',
};
</script>

<template>
    <div
        class="pointer-events-none fixed right-4 top-4 z-[60] flex w-full max-w-sm flex-col gap-2"
        aria-live="polite"
    >
        <TransitionGroup
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-white p-4 text-sm shadow-md"
                :class="styles[toast.variant].box"
                :role="toast.variant === 'danger' ? 'alert' : 'status'"
            >
                <Icon :name="styles[toast.variant].icon" :class="iconColor[toast.variant]" />
                <p class="flex-1 text-neutral-900">{{ toast.message }}</p>
                <button
                    type="button"
                    class="rounded text-neutral-400 hover:text-neutral-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    aria-label="Fechar notificação"
                    @click="dismiss(toast.id)"
                >
                    <Icon name="x" :size="16" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
