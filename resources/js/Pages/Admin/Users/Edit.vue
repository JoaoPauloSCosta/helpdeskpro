<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
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
import {
    formatShortDate,
    userFormErrors,
    type RoleOption,
    type UserEditTarget,
    type UserFormField,
    type UserFormValues,
} from '@/lib/user';
import { useToast } from '@/composables/useToast';

const props = defineProps<{
    user: UserEditTarget;
    values: UserFormValues;
    options: { roles: RoleOption[]; departments: Option[]; statuses: Option[] };
    limits: { name: number; email: number };
    can: { changeAccess: boolean };
    version: string;
}>();

const toast = useToast();
const page = usePage();

/* ---------- Formulário ---------- */

const form = useForm({ ...props.values, version: props.version });

const selectedRole = computed(() => props.options.roles.find((role) => role.value === form.role_id));

/** Inativação pedida neste envio: pede confirmação antes de salvar. */
const deactivating = computed(() => props.user.is_active && form.status === 'inactive');
const reactivating = computed(() => !props.user.is_active && form.status === 'active');

const otherError = (key: 'conflict' | 'version') => (form.errors as Record<string, string | undefined>)[key];


const validate = (): boolean => {
    const errors = userFormErrors(form, props.limits, props.can.changeAccess);

    form.clearErrors();
    form.setError(errors);

    return Object.keys(errors).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document.querySelector<HTMLElement>('#user-edit-form [aria-invalid="true"]')?.focus();
};

// Cancelar, menu e sair passam pela confirmação quando há alterações não salvas.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

const confirmDeactivationOpen = ref(false);

const send = () => {
    confirmDeactivationOpen.value = false;
    setSubmitting(true);

    form
        .transform((data) => ({
            name: data.name,
            email: data.email,
            department_id: data.department_id || null,
            // Papel e situação só vão quando podem ser alterados; caso contrário o backend recusa.
            ...(props.can.changeAccess ? { role_id: data.role_id, status: data.status } : {}),
            version: data.version,
        }))
        .patch(route('admin.users.update', props.user.id), {
            preserveScroll: true,
            onError: () => focusFirstError(),
            onFinish: () => setSubmitting(false),
        });
};

const submit = () => {
    if (form.processing) return;

    if (!form.isDirty) {
        toast.info('Nenhuma alteração para salvar.');
        return;
    }
    if (!validate()) {
        toast.formErrors();
        focusFirstError();
        return;
    }

    if (deactivating.value) confirmDeactivationOpen.value = true;
    else send();
};

/** Descarta o que foi digitado e carrega os valores atuais do servidor. */
const reloadCurrent = () => {
    form.reset();
    router.visit(route('admin.users.edit', props.user.id), { preserveState: false });
};

const clearError = (field: UserFormField) => {
    if (form.errors[field]) form.clearErrors(field);
};

const cancel = () => router.visit(route('admin.users.index'));

// Falhas de rede ou respostas 5xx viram um alerta, sem confirmar nada antes do backend.
const removers: (() => void)[] = [];
onMounted(() => {
    removers.push(
        router.on('exception', (event) => {
            event.preventDefault();
            toast.unexpected();
        }),
        router.on('invalid', (event) => {
            if (event.detail.response.status >= 500) {
                event.preventDefault();
                toast.unexpected();
            }
        }),
    );
});
onUnmounted(() => removers.forEach((remove) => remove()));

const breadcrumbs = computed(() => [
    { label: 'Administração', href: adminHomeHref(page.props.auth.permissions) },
    { label: 'Usuários', href: route('admin.users.index') },
    { label: 'Editar' },
]);
</script>

<template>
    <Head :title="`Editar ${user.name}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Editar usuário" />
            </div>

            <!-- Identificação do usuário selecionado -->
            <Card>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="break-words text-base font-medium text-neutral-900">
                            {{ user.name }}
                            <span v-if="user.is_self" class="text-xs font-normal text-neutral-500">(você)</span>
                        </p>
                        <p class="break-all text-sm text-neutral-600">{{ user.email }}</p>
                        <p class="mt-1 text-xs text-neutral-500">
                            {{ user.role ?? 'Sem papel' }} · Criado em {{ formatShortDate(user.created_at) }}
                        </p>
                    </div>
                    <Badge :tone="user.is_active ? 'success' : 'neutral'" class="self-start sm:self-center">
                        {{ user.is_active ? 'Ativo' : 'Inativo' }}
                    </Badge>
                </div>
            </Card>

            <form id="user-edit-form" class="space-y-6" novalidate @submit.prevent="submit">
                <Alert v-if="otherError('conflict')" variant="danger" title="O usuário foi alterado">
                    <p>{{ otherError('conflict') }}</p>
                    <Button variant="secondary" size="sm" class="mt-3" @click="reloadCurrent">Recarregar dados atuais</Button>
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
                            help="Usado para entrar no sistema. Ao alterá-lo, o novo endereço passa a valer no próximo acesso."
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

                <Card title="Acesso e vínculo organizacional">
                    <div class="space-y-4">
                        <FormField
                            v-if="can.changeAccess"
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
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @change="clearError('role_id')"
                            >
                                <option v-for="o in options.roles" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <div v-else>
                            <p class="text-sm font-medium text-neutral-700">Papel</p>
                            <p class="mt-1 text-sm text-neutral-900">{{ user.role ?? 'Sem papel' }}</p>
                            <p class="mt-1 text-xs text-neutral-500">Você não pode alterar o papel da sua própria conta.</p>
                        </div>

                        <FormField
                            label="Departamento"
                            help="Alterar o departamento não muda o departamento dos chamados já abertos."
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
                                <option v-for="o in options.departments" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>

                        <div class="flex gap-3 rounded-md border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-600">
                            <Icon name="lock" class="shrink-0 text-neutral-400" />
                            <p>
                                A senha não é alterada aqui. O usuário pode defini-la a qualquer momento em
                                “Esqueci minha senha”, na tela de login.
                            </p>
                        </div>
                    </div>
                </Card>

                <Card title="Situação da conta">
                    <StatusRadioGroup
                        v-if="can.changeAccess"
                        v-model="form.status"
                        :options="options.statuses"
                        :descriptions="{ active: 'Pode acessar o sistema.', inactive: 'Não executa operações protegidas.' }"
                        help="Usuários não são excluídos: a inativação preserva chamados, comentários e histórico."
                        :error="form.errors.status"
                        @update:model-value="clearError('status')"
                    >
                        <Alert v-if="deactivating" variant="warning" title="O usuário será inativado" class="mt-4">
                            Ao salvar, as sessões abertas dele serão encerradas e ele não poderá executar operações
                            protegidas. Chamados, comentários e registros de auditoria continuam preservados.
                        </Alert>
                        <Alert v-if="reactivating" variant="info" class="mt-4">
                            Ao salvar, o usuário volta a acessar o sistema com as permissões do papel selecionado.
                        </Alert>
                    </StatusRadioGroup>
                    <div v-else>
                        <p class="text-sm text-neutral-900">{{ user.is_active ? 'Ativo' : 'Inativo' }}</p>
                        <p class="mt-1 text-xs text-neutral-500">Você não pode alterar a situação da sua própria conta.</p>
                    </div>

                    <template #footer>
                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                            <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="cancel">
                                Cancelar
                            </Button>
                            <Button type="submit" class="w-full sm:w-auto" :loading="form.processing">
                                {{ form.processing ? 'Salvando…' : 'Salvar alterações' }}
                            </Button>
                        </div>
                    </template>
                </Card>
            </form>
        </div>

        <ConfirmModal
            :show="confirmDeactivationOpen"
            title="Inativar este usuário?"
            :message="`${user.name} perderá o acesso às operações do sistema e as sessões abertas serão encerradas. O histórico é preservado e a conta pode ser reativada depois.`"
            confirm-label="Inativar e salvar"
            cancel-label="Voltar"
            variant="danger"
            @close="confirmDeactivationOpen = false"
            @confirm="send"
        />

        <ConfirmModal
            :show="leaveOpen"
            title="Descartar as alterações?"
            message="As alterações ainda não foram salvas e serão perdidas."
            confirm-label="Descartar e sair"
            cancel-label="Continuar editando"
            variant="danger"
            @close="stayOnPage"
            @confirm="confirmLeave"
        />
    </AuthenticatedLayout>
</template>
