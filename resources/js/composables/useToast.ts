import { reactive } from 'vue';

export type ToastVariant = 'success' | 'info' | 'warning' | 'danger';

export interface Toast {
    id: number;
    variant: ToastVariant;
    message: string;
}

export const FORM_ERRORS_MESSAGE = 'Corrija os campos destacados no formulário.';
export const UNEXPECTED_ERROR_MESSAGE =
    'Ocorreu uma falha inesperada e nada foi gravado. Verifique sua conexão e tente novamente.';

const toasts = reactive<Toast[]>([]);
let nextId = 1;

const dismiss = (id: number) => {
    const index = toasts.findIndex((toast) => toast.id === id);
    if (index !== -1) toasts.splice(index, 1);
};

const push = (variant: ToastVariant, message: string) => {
    // Evita notificações duplicadas: a mesma mensagem já visível não é empilhada de novo.
    const visible = toasts.find((toast) => toast.variant === variant && toast.message === message);
    if (visible) return visible.id;

    const id = nextId++;
    toasts.push({ id, variant, message });

    // Todas as variantes, inclusive erros, somem após 5s (design-system.md, seção 6.5).
    setTimeout(() => dismiss(id), 5000);

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
        /** Falha de validação feita no navegador; os detalhes ficam junto de cada campo. */
        formErrors: () => push('danger', FORM_ERRORS_MESSAGE),
        /** Falha de rede ou resposta 5xx: nada foi confirmado pelo servidor. */
        unexpected: () => push('danger', UNEXPECTED_ERROR_MESSAGE),
    };
}
