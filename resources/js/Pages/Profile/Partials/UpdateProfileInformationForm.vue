<script setup lang="ts">
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { useToast } from '@/composables/useToast';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();

const page = usePage();
const toast = useToast();

const form = useForm({
    name: page.props.auth.user.name,
    email: page.props.auth.user.email,
});

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => {
            // Alinha o formulário ao que o servidor gravou (ex.: e-mail em minúsculas).
            const saved = page.props.auth.user;
            form.defaults({ name: saved.name, email: saved.email }).reset();
            toast.success('Alterações salvas com sucesso.');
        },
        onError: (errors) => {
            if (errors.name || errors.email) return;
            toast.error('Não foi possível salvar as alterações. Tente novamente.');
        },
    });
};

const cancel = () => {
    form.reset();
    form.clearErrors();
};
</script>

<template>
    <Card title="Informações pessoais">
        <form class="space-y-4" novalidate @submit.prevent="submit">
            <FormField label="Nome completo" :error="form.errors.name" required v-slot="{ id, describedby, invalid }">
                <TextInput
                    :id="id"
                    v-model="form.name"
                    type="text"
                    autocomplete="name"
                    required
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <FormField label="E-mail" :error="form.errors.email" required v-slot="{ id, describedby, invalid }">
                <TextInput
                    :id="id"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    required
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <div v-if="mustVerifyEmail && page.props.auth.user.email_verified_at === null" class="text-sm text-neutral-700">
                <p>
                    Seu e-mail ainda não foi verificado.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="rounded-md font-semibold text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                    >
                        Reenviar e-mail de verificação
                    </Link>
                </p>
                <p v-if="status === 'verification-link-sent'" class="mt-2 font-medium text-success-text" role="status">
                    Um novo link de verificação foi enviado para o seu e-mail.
                </p>
            </div>

            <p class="text-xs text-neutral-500">
                Ao alterar o e-mail, o novo endereço precisará ser verificado novamente.
            </p>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button v-if="form.isDirty" variant="secondary" :disabled="form.processing" @click="cancel">
                    Cancelar
                </Button>
                <Button type="submit" :loading="form.processing" :disabled="!form.isDirty">
                    Salvar alterações
                </Button>
            </div>
        </form>
    </Card>
</template>
