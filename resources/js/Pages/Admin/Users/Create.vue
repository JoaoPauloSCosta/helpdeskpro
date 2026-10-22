<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Select from '@/Components/Select.vue';
import StatusRadioGroup from '@/Components/StatusRadioGroup.vue';
import TextInput from '@/Components/TextInput.vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { adminHomeHref } from '@/lib/navigation';
import type { Option } from '@/lib/ticket';
import { userFormErrors, type RoleOption, type UserFormField } from '@/lib/user';

const props = defineProps<{
    roles: RoleOption[];
    departments: Option[];
    statuses: Option[];
    limits: { name: number; email: number };
}>();

/* ---------- Formulário ---------- */

// Somente o que o operador informa. Senha, verificação de e-mail e tokens ficam no backend.
const form = useForm({
    name: '',
    email: '',
    role_id: '',
    department_id: '',
    status: 'active',
});

type Field = UserFormField;

const hasRoles = computed(() => props.roles.length > 0);
const selectedRole = computed(() => props.roles.find((role) => role.value === form.role_id));

const validate = (): boolean => {
    const errors = userFormErrors(form, props.limits);

    form.clearErrors();
    form.setError(errors);

    return Object.keys(errors).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document.querySelector<HTMLElement>('#user-form [aria-invalid="true"]')?.focus();
};

// Cancelar, menu e sair passam pela confirmação quando há dados preenchidos.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

/** Falha inesperada (rede ou erro do servidor), distinta dos erros de validação. */
const unexpectedError = ref(false);

const submit = () => {
    if (form.processing || !hasRoles.value) return;
    if (!validate()) {
        focusFirstError();
        return;
    }

    unexpectedError.value = false;
    setSubmitting(true);
    // Departamento vazio vai como nulo: é opcional.
    form.transform((data) => ({ ...data, department_id: data.department_id || null })).post(route('admin.users.store'), {
        preserveScroll: true,
        onError: () => focusFirstError(),
        onFinish: () => setSubmitting(false),
    });
};

const clearError = (field: Field) => {
    if (form.errors[field]) form.clearErrors(field);
};

const cancel = () => router.visit(route('admin.users.index'));

// Falhas de rede ou respostas 5xx viram um alerta, sem confirmar nada antes do backend.
const removers: (() => void)[] = [];
onMounted(() => {
    removers.push(
        router.on('exception', (event) => {
            event.preventDefault();
            unexpectedError.value = true;
        }),
        router.on('invalid', (event) => {
            if (event.detail.response.status >= 500) {
                event.preventDefault();
                unexpectedError.value = true;
            }
        }),
    );
});
onUnmounted(() => removers.forEach((remove) => remove()));

const page = usePage();

const breadcrumbs = computed(() => [
    { label: 'Administração', href: adminHomeHref(page.props.auth.permissions) },
    { label: 'Usuários', href: route('admin.users.index') },
    { label: 'Novo usuário' },
]);
</script>

<template>
    <Head title="Criar usuário" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Criar usuário" subtitle="Cadastre uma pessoa e defina o acesso inicial dela ao sistema." />
            </div>

            <form id="user-form" class="space-y-6" novalidate @submit.prevent="submit">
                <Alert v-if="unexpectedError" variant="danger" title="Não foi possível criar o usuário">
                    Ocorreu uma falha inesperada e nada foi gravado. Verifique sua conexão e tente novamente.
                </Alert>

                <Alert v-if="!hasRoles" variant="warning" title="Nenhum papel disponível">
                    Não há papéis que você possa atribuir. Procure quem administra papéis e permissões.
                </Alert>

                <Card title="Dados pessoais">
                    <div class="space-y-4">
                        <FormField label="Nome completo" required :error="form.errors.name" v-slot="{ id, describedby, invalid }">
                            <TextInput
                                :id="id"
                                v-model="form.name"
                                autocomplete="off"
                                required
                                :maxlength="limits.name"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @input="clearError('name')"
                            />
                        </FormField>

                        <FormField
                            label="E-mail"
                            required
                            help="Usado para entrar no sistema e para receber o link de definição de senha."
                            :error="form.errors.email"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.email"
                                type="email"
                                autocomplete="off"
                                required
                                :maxlength="limits.email"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @input="clearError('email')"
                            />
                        </FormField>
                    </div>
                </Card>

                <Card title="Acesso ao sistema">
                    <div class="space-y-4">
                        <FormField
                            label="Papel"
                            required
                            :help="selectedRole?.description ?? 'O papel define as permissões do usuário.'"
                            :error="form.errors.role_id"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <Select
                                :id="id"
                                v-model="form.role_id"
                                placeholder="Selecione um papel"
                                required
                                :disabled="!hasRoles"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @change="clearError('role_id')"
                            >
                                <option v-for="o in roles" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>

                        <div class="flex gap-3 rounded-md border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-600">
                            <Icon name="lock" class="shrink-0 text-neutral-400" />
                            <p>
                                Você não define a senha. Usuários ativos recebem por e-mail um link para criá-la; o link
                                também pode ser solicitado depois em “Esqueci minha senha”, na tela de login.
                            </p>
                        </div>
                    </div>
                </Card>

                <Card title="Configuração inicial">
                    <div class="space-y-4">
                        <FormField
                            label="Departamento"
                            help="Opcional. Necessário para que o usuário abra chamados."
                            :error="form.errors.department_id"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <Select
                                :id="id"
                                v-model="form.department_id"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @change="clearError('department_id')"
                            >
                                <option value="">Sem departamento</option>
                                <option v-for="o in departments" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>

                        <StatusRadioGroup
                            v-model="form.status"
                            legend="Situação inicial"
                            :options="statuses"
                            :descriptions="{
                                active: 'Pode acessar o sistema e recebe o convite.',
                                inactive: 'Não executa operações e não recebe convite.',
                            }"
                            help="Usuários não são excluídos: quando necessário, são inativados para preservar o histórico."
                            :error="form.errors.status"
                            @update:model-value="clearError('status')"
                        />
                    </div>

                    <template #footer>
                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                            <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="cancel">
                                Cancelar
                            </Button>
                            <Button type="submit" class="w-full sm:w-auto" :loading="form.processing" :disabled="!hasRoles">
                                {{ form.processing ? 'Criando usuário…' : 'Criar usuário' }}
                            </Button>
                        </div>
                    </template>
                </Card>
            </form>
        </div>

        <ConfirmModal
            :show="leaveOpen"
            title="Descartar o cadastro?"
            message="As informações preenchidas ainda não foram enviadas e serão perdidas."
            confirm-label="Descartar e sair"
            cancel-label="Continuar editando"
            variant="danger"
            @close="stayOnPage"
            @confirm="confirmLeave"
        />
    </AuthenticatedLayout>
</template>
