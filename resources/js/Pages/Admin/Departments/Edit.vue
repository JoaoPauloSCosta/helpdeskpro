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
import PageHeader from '@/Components/PageHeader.vue';
import StatusRadioGroup from '@/Components/StatusRadioGroup.vue';
import Textarea from '@/Components/Textarea.vue';
import TextInput from '@/Components/TextInput.vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    departmentFormErrors,
    type DepartmentEditTarget,
    type DepartmentFormField,
    type DepartmentFormValues,
} from '@/lib/department';
import { adminHomeHref } from '@/lib/navigation';
import type { Option } from '@/lib/ticket';
import { formatShortDate } from '@/lib/user';

const props = defineProps<{
    department: DepartmentEditTarget;
    values: DepartmentFormValues;
    statuses: Option[];
    limits: { name: number; description: number };
    version: string;
}>();

const page = usePage();

/* ---------- Formulário ---------- */

const form = useForm({ ...props.values, version: props.version });

/** Inativação pedida neste envio: pede confirmação antes de salvar. */
const deactivating = computed(() => props.department.is_active && form.status === 'inactive');
const reactivating = computed(() => !props.department.is_active && form.status === 'active');

const otherError = (key: 'conflict' | 'version') => (form.errors as Record<string, string | undefined>)[key];

const noChanges = ref(false);
const serverInfo = computed(() => page.props.flash?.info ?? null);
const unexpectedError = ref(false);

const count = (value: number, singular: string, plural: string) =>
    `${value.toLocaleString('pt-BR')} ${value === 1 ? singular : plural}`;
const charCount = (value: string, max: number) =>
    `${value.length.toLocaleString('pt-BR')} / ${max.toLocaleString('pt-BR')}`;

const validate = (): boolean => {
    const errors = departmentFormErrors(form, props.limits);

    form.clearErrors();
    form.setError(errors);

    return Object.keys(errors).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document.querySelector<HTMLElement>('#department-edit-form [aria-invalid="true"]')?.focus();
};

// Cancelar, menu e sair passam pela confirmação quando há alterações não salvas.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

const confirmDeactivationOpen = ref(false);

const send = () => {
    confirmDeactivationOpen.value = false;
    unexpectedError.value = false;
    setSubmitting(true);

    form.patch(route('admin.departments.update', props.department.id), {
        preserveScroll: true,
        onError: () => focusFirstError(),
        onFinish: () => setSubmitting(false),
    });
};

const submit = () => {
    if (form.processing) return;

    noChanges.value = false;
    if (!form.isDirty) {
        noChanges.value = true;
        return;
    }
    if (!validate()) {
        focusFirstError();
        return;
    }

    if (deactivating.value) confirmDeactivationOpen.value = true;
    else send();
};

/** Descarta o que foi digitado e carrega os valores atuais do servidor. */
const reloadCurrent = () => {
    form.reset();
    router.visit(route('admin.departments.edit', props.department.id), { preserveState: false });
};

const clearError = (field: DepartmentFormField) => {
    noChanges.value = false;
    if (form.errors[field]) form.clearErrors(field);
};

const cancel = () => router.visit(route('admin.departments.index'));

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

const breadcrumbs = computed(() => [
    { label: 'Administração', href: adminHomeHref(page.props.auth.permissions) },
    { label: 'Departamentos', href: route('admin.departments.index') },
    { label: 'Editar' },
]);
</script>

<template>
    <Head :title="`Editar ${department.name}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Editar departamento" subtitle="Altere as informações necessárias e salve as modificações." />
            </div>

            <!-- Identificação do departamento selecionado -->
            <Card>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="break-words text-base font-medium text-neutral-900">{{ department.name }}</p>
                        <p class="mt-1 text-xs text-neutral-500">
                            {{ count(department.users_count, 'usuário', 'usuários') }} ·
                            {{ count(department.tickets_count, 'chamado', 'chamados') }} · Criado em
                            {{ formatShortDate(department.created_at) }} · Atualizado em
                            {{ formatShortDate(department.updated_at) }}
                        </p>
                    </div>
                    <Badge :tone="department.is_active ? 'success' : 'neutral'" class="self-start sm:self-center">
                        {{ department.is_active ? 'Ativo' : 'Inativo' }}
                    </Badge>
                </div>
            </Card>

            <form id="department-edit-form" class="space-y-6" novalidate @submit.prevent="submit">
                <Alert v-if="otherError('conflict')" variant="danger" title="O departamento foi alterado">
                    <p>{{ otherError('conflict') }}</p>
                    <Button variant="secondary" size="sm" class="mt-3" @click="reloadCurrent">Recarregar dados atuais</Button>
                </Alert>

                <Alert v-if="otherError('version')" variant="danger" title="Não foi possível salvar">
                    {{ otherError('version') }}
                </Alert>

                <Alert v-if="unexpectedError" variant="danger" title="Não foi possível salvar as alterações">
                    Ocorreu uma falha inesperada e nada foi gravado. Verifique sua conexão e tente novamente.
                </Alert>

                <Alert v-if="noChanges || serverInfo" variant="info">
                    {{ serverInfo ?? 'Nenhuma alteração para salvar.' }}
                </Alert>

                <Card title="Dados do departamento">
                    <div class="space-y-4">
                        <FormField
                            label="Nome do departamento"
                            required
                            help="Renomear não altera os chamados: o histórico mantém o nome da época de cada evento."
                            :error="form.errors.name"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.name"
                                placeholder="Ex.: Tecnologia da Informação"
                                autocomplete="off"
                                required
                                :maxlength="limits.name"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @input="clearError('name')"
                            />
                        </FormField>

                        <FormField label="Descrição" help="Opcional." :error="form.errors.description" v-slot="{ id, describedby, invalid }">
                            <Textarea
                                :id="id"
                                v-model="form.description"
                                :rows="4"
                                placeholder="Descreva a finalidade ou as responsabilidades do departamento..."
                                :maxlength="limits.description"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @input="clearError('description')"
                            />
                            <p class="mt-1 text-right text-xs text-neutral-500" aria-hidden="true">
                                {{ charCount(form.description, limits.description) }}
                            </p>
                        </FormField>
                    </div>
                </Card>

                <Card title="Situação">
                    <StatusRadioGroup
                        v-model="form.status"
                        :options="statuses"
                        :descriptions="{
                            active: 'Disponível para associar usuários e abrir chamados.',
                            inactive: 'Não pode ser usado em novos vínculos.',
                        }"
                        help="Departamentos não são excluídos: a inativação preserva usuários, chamados e histórico."
                        :error="form.errors.status"
                        @update:model-value="clearError('status')"
                    >
                        <Alert v-if="deactivating" variant="warning" title="O departamento será inativado" class="mt-4">
                            <p>
                                Ele deixará de aparecer nas opções de novos usuários, de transferência de chamados e de
                                pendências. Os chamados já vinculados ({{ department.tickets_count.toLocaleString('pt-BR') }})
                                continuam no departamento.
                            </p>
                            <p v-if="department.active_users_count" class="mt-2">
                                {{ count(department.active_users_count, 'usuário ativo continua', 'usuários ativos continuam') }}
                                vinculados a ele, mas não poderão abrir chamados até serem transferidos para um departamento ativo.
                            </p>
                        </Alert>
                        <Alert v-if="reactivating" variant="info" class="mt-4">
                            Ao salvar, o departamento volta a ficar disponível para novos vínculos.
                        </Alert>
                    </StatusRadioGroup>

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
            title="Inativar este departamento?"
            :message="`${department.name} não poderá ser usado em novos vínculos. Usuários, chamados e histórico continuam preservados, e o departamento pode ser reativado depois.`"
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
