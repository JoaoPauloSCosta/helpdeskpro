<script setup lang="ts">
import { watch } from 'vue';
import Button from '@/Components/Button.vue';
import { useToast } from '@/composables/useToast';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const toast = useToast();

// Confirmação do reenvio do link de verificação, por toast.
watch(
    () => props.status,
    (status) => {
        if (status === 'verification-link-sent') toast.success('Um novo link de verificação foi enviado ao e-mail informado no cadastro.');
    },
    { immediate: true },
);
</script>

<template>
    <AuthLayout security-note="Por segurança, o link expira em 60 minutos">
        <Head title="Confirmar e-mail" />

        <p class="mb-2.5 text-xs font-bold uppercase tracking-widest text-primary-600">
            Confirmação de cadastro
        </p>
        <h2 class="mb-2 text-3xl font-bold tracking-tight text-neutral-900">
            Confirme seu e-mail
        </h2>
        <p class="mb-8 text-sm text-neutral-500">
            Enviamos um link de confirmação para o e-mail informado no cadastro. Clique nele para
            começar a usar o sistema. Não recebeu? Confira a caixa de spam ou peça um novo envio.
        </p>

        <form novalidate @submit.prevent="submit">
            <Button type="submit" size="lg" block :loading="form.processing">
                Reenviar link de confirmação
            </Button>
        </form>

        <p class="mt-6 text-center text-sm text-neutral-500">
            Cadastrou o e-mail errado?
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="ms-1 rounded-md font-semibold text-primary-600 hover:text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            >
                Sair
            </Link>
        </p>
    </AuthLayout>
</template>
