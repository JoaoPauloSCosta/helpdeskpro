export type Tone = 'neutral' | 'primary' | 'info' | 'success' | 'warning' | 'danger';

/** Classes de fundo/texto/borda de cada tom (design-system.md, seção 2.3 e 3). */
export const toneClasses: Record<Tone, string> = {
    neutral: 'bg-neutral-100 text-neutral-600',
    primary: 'bg-primary-100 text-primary-700',
    info: 'bg-primary-50 text-primary-800',
    success: 'bg-success-bg text-success-text',
    warning: 'bg-warning-bg text-warning-text',
    danger: 'bg-danger-bg text-danger-text',
};

/** Base compartilhada por input, select e textarea. */
const inputBase =
    'block w-full rounded-md border-neutral-300 text-sm text-neutral-900 shadow-sm placeholder:text-neutral-400 focus:border-primary-500 focus:ring-primary-500 disabled:cursor-not-allowed disabled:bg-neutral-100 disabled:text-neutral-500';
const inputInvalid = 'border-danger focus:border-danger focus:ring-danger';

export const inputClasses = (invalid?: boolean) => [inputBase, invalid && inputInvalid];
