<script setup lang="ts">
import { watch } from 'vue';
import Button from '@/Components/Button.vue';
import CheckboxField from '@/Components/CheckboxField.vue';
import FormField from '@/Components/FormField.vue';
import IconInput from '@/Components/IconInput.vue';
import { useToast } from '@/composables/useToast';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    canResetPassword?: boolean;
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
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <AuthLayout security-note="Seus dados são tratados com segurança">
        <Head title="Acesso" />

        <p class="mb-2.5 text-xs font-bold uppercase tracking-widest text-primary-600">
            Bem-vindo de volta
        </p>
        <h2 class="mb-2 text-3xl font-bold tracking-tight text-neutral-900">
            Acesse sua conta
        </h2>
        <p class="mb-8 text-sm text-neutral-500">
            Entre com seus dados para continuar no sistema.
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

            <FormField label="Senha" :error="form.errors.password" v-slot="{ id, describedby, invalid }">
                <IconInput
                    :id="id"
                    v-model="form.password"
                    icon="lock"
                    type="password"
                    placeholder="Digite sua senha"
                    autocomplete="current-password"
                    required
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <div class="flex items-center justify-between gap-3 pb-2">
                <CheckboxField v-model="form.remember" label="Manter conectado" />
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="rounded-md text-sm font-semibold text-primary-600 hover:text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                >
                    Esqueceu a senha?
                </Link>
            </div>

            <Button type="submit" size="lg" block :loading="form.processing">
                Entrar na plataforma
            </Button>
        </form>

        <p class="mt-6 text-center text-sm text-neutral-500">
            Ainda não tem uma conta?
            <Link
                :href="route('register')"
                class="ms-1 rounded-md font-semibold text-primary-600 hover:text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            >
                Cadastre-se
            </Link>
        </p>
    </AuthLayout>
</template>
