import { reactive } from 'vue';

export type ToastVariant = 'success' | 'info' | 'warning' | 'danger';

export interface Toast {
    id: number;
    variant: ToastVariant;
    message: string;
}

const toasts = reactive<Toast[]>([]);
let nextId = 1;

const dismiss = (id: number) => {
    const index = toasts.findIndex((toast) => toast.id === id);
    if (index !== -1) toasts.splice(index, 1);
};

const push = (variant: ToastVariant, message: string) => {
    const id = nextId++;
    toasts.push({ id, variant, message });

    // Erros permanecem até serem fechados (design-system.md, seção 6.5).
    if (variant !== 'danger') setTimeout(() => dismiss(id), 5000);

    return id;
};

export function useToast() {
    return {
        toasts,
        dismiss,
        success: (message: string) => push('success', message),
        info: (message: string) => push('info', message),
        warning: (message: string) => push('warning', message),
        error: (message: string) => push('danger', message),
    };
}
