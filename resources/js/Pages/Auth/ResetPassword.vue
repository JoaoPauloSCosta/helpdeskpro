<script setup lang="ts">
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import IconInput from '@/Components/IconInput.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    email: string;
    token: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout security-note="Seus dados são tratados com segurança">
        <Head title="Redefinir senha" />

        <p class="mb-2.5 text-xs font-bold uppercase tracking-widest text-primary-600">
            Redefinir senha
        </p>
        <h2 class="mb-2 text-3xl font-bold tracking-tight text-neutral-900">
            Crie uma nova senha
        </h2>
        <p class="mb-8 text-sm text-neutral-500">
            Escolha uma senha nova para a sua conta. Depois, é só entrar com ela.
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
                    :autofocus="!form.email || undefined"
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <FormField
                label="Nova senha"
                help="Use pelo menos 8 caracteres."
                :error="form.errors.password"
                v-slot="{ id, describedby, invalid }"
            >
                <IconInput
                    :id="id"
                    v-model="form.password"
                    icon="lock"
                    type="password"
                    placeholder="Crie uma senha"
                    autocomplete="new-password"
                    minlength="8"
                    required
                    :autofocus="!!form.email || undefined"
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <FormField
                label="Confirmar nova senha"
                :error="form.errors.password_confirmation"
                v-slot="{ id, describedby, invalid }"
            >
                <IconInput
                    :id="id"
                    v-model="form.password_confirmation"
                    icon="lock"
                    type="password"
                    placeholder="Repita a senha"
                    autocomplete="new-password"
                    required
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <div class="pt-2">
                <Button type="submit" size="lg" block :loading="form.processing">
                    Salvar nova senha
                </Button>
            </div>
        </form>

        <p class="mt-6 text-center text-sm text-neutral-500">
            Não precisa mais?
            <Link
                :href="route('login')"
                class="ms-1 rounded-md font-semibold text-primary-600 hover:text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            >
                Voltar para o login
            </Link>
        </p>
    </AuthLayout>
</template>
