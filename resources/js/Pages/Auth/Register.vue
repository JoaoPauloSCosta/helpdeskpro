<script setup lang="ts">
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import IconInput from '@/Components/IconInput.vue';
import Select from '@/Components/Select.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    departments: { id: number; name: string }[];
}>();

const form = useForm({
    name: '',
    email: '',
    department_id: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout security-note="Seus dados são tratados com segurança">
        <Head title="Cadastre-se" />

        <p class="mb-2.5 text-xs font-bold uppercase tracking-widest text-primary-600">
            Comece agora
        </p>
        <h2 class="mb-2 text-3xl font-bold tracking-tight text-neutral-900">
            Crie sua conta
        </h2>
        <p class="mb-8 text-sm text-neutral-500">
            Preencha os dados abaixo para solicitar seu acesso.
        </p>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <FormField label="Nome do usuário" :error="form.errors.name" v-slot="{ id, describedby, invalid }">
                <IconInput
                    :id="id"
                    v-model="form.name"
                    icon="user"
                    type="text"
                    placeholder="Como podemos chamar você?"
                    autocomplete="name"
                    required
                    autofocus
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <FormField label="E-mail" :error="form.errors.email" v-slot="{ id, describedby, invalid }">
                <IconInput
                    :id="id"
                    v-model="form.email"
                    icon="envelope"
                    type="email"
                    placeholder="voce@empresa.com.br"
                    autocomplete="username"
                    required
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <Alert v-if="!props.departments.length" variant="warning" title="Cadastro indisponível">
                Não há departamentos disponíveis no momento, então não é possível concluir o cadastro.
                Procure o responsável pelo sistema.
            </Alert>

            <FormField
                v-else
                label="Departamento"
                required
                :error="form.errors.department_id"
                v-slot="{ id, describedby, invalid }"
            >
                <Select
                    :id="id"
                    v-model="form.department_id"
                    placeholder="Selecione seu departamento"
                    required
                    :invalid="invalid"
                    :aria-describedby="describedby"
                >
                    <option v-for="department in props.departments" :key="department.id" :value="department.id">
                        {{ department.name }}
                    </option>
                </Select>
            </FormField>

            <FormField
                label="Senha"
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
                    :invalid="invalid"
                    :aria-describedby="describedby"
                />
            </FormField>

            <FormField
                label="Confirmar senha"
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
                <Button
                    type="submit"
                    size="lg"
                    block
                    :loading="form.processing"
                    :disabled="!props.departments.length"
                >
                    Criar conta
                </Button>
            </div>
        </form>

        <p class="mt-6 text-center text-sm text-neutral-500">
            Já tem uma conta?
            <Link
                :href="route('login')"
                class="ms-1 rounded-md font-semibold text-primary-600 hover:text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            >
                Voltar para o login
            </Link>
        </p>
    </AuthLayout>
</template>
