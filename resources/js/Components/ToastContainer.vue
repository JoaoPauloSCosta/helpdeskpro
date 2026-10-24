<script setup lang="ts">
import Icon, { type IconName } from '@/Components/Icon.vue';
import { FORM_ERRORS_MESSAGE, useToast, type ToastVariant } from '@/composables/useToast';
import { router, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, watch } from 'vue';

const { toasts, dismiss, ...toast } = useToast();
const page = usePage();

/**
 * Feedback vindo do servidor, centralizado aqui para valer em todas as telas.
 * Cada resposta traz um novo objeto `flash`, então ações repetidas voltam a notificar;
 * recargas parciais mantêm o objeto e não repetem o toast.
 */
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.info) toast.info(flash.info);
        if (flash?.warning) toast.warning(flash.warning);
    },
    { immediate: true },
);

/**
 * Erros de validação e regras de negócio devolvidos pelo servidor. Um único erro mostra a
 * própria mensagem; vários remetem aos campos destacados. `conflict` fica de fora: a tela o
 * apresenta junto da ação de recarregar os dados atuais.
 */
const removers: (() => void)[] = [];
onMounted(() => {
    removers.push(
        router.on('error', (event) => {
            const messages = Object.entries(event.detail.errors)
                .filter(([key]) => key !== 'conflict')
                .map(([, message]) => message);

            if (messages.length === 1) toast.error(messages[0]);
            else if (messages.length > 1) toast.error(FORM_ERRORS_MESSAGE);
        }),
    );
});
onUnmounted(() => removers.forEach((remove) => remove()));

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
