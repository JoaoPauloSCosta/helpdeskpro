import { onMounted, onUnmounted, ref } from 'vue';
import type { Method } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';

/**
 * Pede confirmação antes de sair de um formulário com alterações não enviadas: navegações do
 * Inertia (links, menu, sair) abrem um modal; fechar ou recarregar a aba usa o aviso do navegador.
 * O próprio envio do formulário deve ser marcado com `setSubmitting` para não ser interceptado.
 */
export function useLeaveGuard(isDirty: () => boolean) {
    const leaveOpen = ref(false);
    let allowLeave = false;
    let submitting = false;
    let pendingVisit: { url: string; method: Method } | null = null;

    const confirmLeave = () => {
        if (!pendingVisit) return;
        allowLeave = true;
        leaveOpen.value = false;
        router.visit(pendingVisit.url, { method: pendingVisit.method });
    };

    const stayOnPage = () => {
        leaveOpen.value = false;
        pendingVisit = null;
    };

    const setSubmitting = (value: boolean) => (submitting = value);

    const onBeforeUnload = (event: BeforeUnloadEvent) => {
        if (isDirty() && !submitting) event.preventDefault();
    };

    let removeBeforeListener: (() => void) | undefined;

    onMounted(() => {
        removeBeforeListener = router.on('before', (event) => {
            if (allowLeave || submitting || !isDirty()) return;
            event.preventDefault();
            pendingVisit = { url: event.detail.visit.url.href, method: event.detail.visit.method };
            leaveOpen.value = true;
        });
        window.addEventListener('beforeunload', onBeforeUnload);
    });

    onUnmounted(() => {
        removeBeforeListener?.();
        window.removeEventListener('beforeunload', onBeforeUnload);
    });

    return { leaveOpen, confirmLeave, stayOnPage, setSubmitting };
}
