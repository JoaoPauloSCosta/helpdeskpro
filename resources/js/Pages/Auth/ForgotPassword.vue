<script setup lang="ts">
import { watch } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import IconInput from '@/Components/IconInput.vue';
import { useToast } from '@/composables/useToast';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    status?: string;
}>();

const toast = useToast();

// Confirmação do envio do link de redefinição (e da nova senha), sempre por toast.
watch(
    () => props.status,
    (status) => {
        if (status) toast.success(status);
    },
    { immediate: true },
);

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <AuthLayout security-note="Por segurança, o link expira em 60 minutos">
        <Head title="Recuperar senha" />

        <p class="mb-2.5 text-xs font-bold uppercase tracking-widest text-primary-600">
            Recuperar acesso
        </p>
        <h2 class="mb-2 text-3xl font-bold tracking-tight text-neutral-900">
            Esqueceu sua senha?
        </h2>
        <p class="mb-8 text-sm text-neutral-500">
            Informe o e-mail da sua conta e enviaremos um link para você criar uma nova senha.
        </p>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <FormField label="E-mail" :error="form.errors.email" v-slot="{ id, describedby, invalid }">
                <IconInput
                    :id="id"
                    v-model="form.email"
                    icon="envelope"
                    type="email"
                    placeholder="voce@empresa.com.br"
                    autocomplete="username"
                    required
                    autofocus
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <div class="pt-2">
                <Button type="submit" size="lg" block :loading="form.processing">
                    Enviar link de recuperação
                </Button>
            </div>
        </form>

        <p class="mt-6 text-center text-sm text-neutral-500">
            Lembrou a senha?
            <Link
                :href="route('login')"
                class="ms-1 rounded-md font-semibold text-primary-600 hover:text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            >
                Voltar para o login
            </Link>
        </p>
    </AuthLayout>
</template>
