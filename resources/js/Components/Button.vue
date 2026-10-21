<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Spinner from '@/Components/Spinner.vue';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        size?: 'sm' | 'md' | 'lg';
        type?: 'button' | 'submit' | 'reset';
        href?: string;
        loading?: boolean;
        disabled?: boolean;
        block?: boolean;
    }>(),
    { variant: 'primary', size: 'md', type: 'button' },
);

const variants = {
    primary: 'bg-primary-500 text-white hover:bg-primary-600 active:bg-primary-700',
    secondary:
        'border border-neutral-300 bg-white text-neutral-700 shadow-sm hover:bg-neutral-50 active:bg-neutral-100',
    danger: 'bg-danger text-white hover:bg-danger-700 active:bg-danger-700',
    ghost: 'text-primary-600 hover:bg-primary-50 active:bg-primary-100',
};

const sizes = {
    sm: 'h-8 px-3 text-sm',
    md: 'h-10 px-4 text-sm',
    lg: 'h-12 px-5 text-base',
};

const inactive = computed(() => props.disabled || props.loading);

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-md font-semibold transition duration-150 ease-in-out',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2',
    variants[props.variant],
    sizes[props.size],
    props.block && 'w-full',
    inactive.value && 'cursor-not-allowed opacity-50',
]);
</script>

<template>
    <Link
        v-if="href && !inactive"
        :href="href"
        :class="classes"
    >
        <slot />
    </Link>
    <button
        v-else
        :type="type"
        :class="classes"
        :disabled="inactive"
        :aria-busy="loading || undefined"
    >
        <Spinner v-if="loading" />
        <slot />
    </button>
</template>
