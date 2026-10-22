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
import Textarea from '@/Components/Textarea.vue';
import TextInput from '@/Components/TextInput.vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { adminHomeHref } from '@/lib/navigation';
import { roleFormErrors, type RoleEditTarget, type RoleFormField, type RoleFormValues } from '@/lib/role';
import { formatShortDate } from '@/lib/user';

const props = defineProps<{
    role: RoleEditTarget;
    values: RoleFormValues;
    limits: { name: number; description: number };
    version: string;
}>();

const page = usePage();

/* ---------- Formulário ---------- */

const form = useForm({ ...props.values, version: props.version });

const otherError = (key: 'conflict' | 'version') => (form.errors as Record<string, string | undefined>)[key];

const noChanges = ref(false);
const serverInfo = computed(() => page.props.flash?.info ?? null);
const unexpectedError = ref(false);

const count = (value: number, singular: string, plural: string) =>
    `${value.toLocaleString('pt-BR')} ${value === 1 ? singular : plural}`;
const charCount = (value: string, max: number) =>
    `${value.length.toLocaleString('pt-BR')} / ${max.toLocaleString('pt-BR')}`;

const validate = (): boolean => {
    const errors = roleFormErrors(form, props.limits);

    form.clearErrors();
    form.setError(errors);

    return Object.keys(errors).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document.querySelector<HTMLElement>('#role-edit-form [aria-invalid="true"]')?.focus();
};

// Cancelar, menu e sair passam pela confirmação quando há alterações não salvas.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

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

    unexpectedError.value = false;
    setSubmitting(true);
    form.patch(route('admin.roles.update', props.role.id), {
        preserveScroll: true,
        onError: () => focusFirstError(),
        onFinish: () => setSubmitting(false),
    });
};

/** Descarta o que foi digitado e carrega os valores atuais do servidor. */
const reloadCurrent = () => {
    form.reset();
    router.visit(route('admin.roles.edit', props.role.id), { preserveState: false });
};

const clearError = (field: RoleFormField) => {
    noChanges.value = false;
    if (form.errors[field]) form.clearErrors(field);
};

const cancel = () => router.visit(route('admin.roles.index'));

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
    { label: 'Papéis e permissões', href: route('admin.roles.index') },
    { label: 'Editar papel' },
]);
</script>

<template>
    <Head :title="`Editar ${role.name}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Editar papel" subtitle="Atualize os dados cadastrais deste papel." />
            </div>

            <!-- Identificação do papel selecionado -->
            <Card>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="break-words text-base font-medium text-neutral-900">{{ role.name }}</p>
                        <p class="break-all font-mono text-xs text-neutral-500">{{ role.slug }}</p>
                        <p class="mt-1 text-xs text-neutral-500">
                            {{ count(role.permissions_count, 'permissão', 'permissões') }} ·
                            {{ count(role.users_count, 'usuário associado', 'usuários associados') }} · Criado em
                            {{ formatShortDate(role.created_at) }} · Atualizado em {{ formatShortDate(role.updated_at) }}
                        </p>
                    </div>
                    <Badge v-if="role.is_system" tone="neutral" class="self-start sm:self-center">Sistema</Badge>
                </div>
            </Card>

            <form id="role-edit-form" novalidate @submit.prevent="submit">
                <Card title="Dados do papel">
                    <div class="space-y-4">
                        <Alert v-if="otherError('conflict')" variant="danger" title="O papel foi alterado">
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

                        <FormField
                            label="Nome do papel"
                            required
                            :help="
                                role.is_system
                                    ? 'Papel do sistema: o nome é mantido para corresponder aos perfis de referência. A descrição pode ser alterada.'
                                    : 'O identificador interno não muda ao renomear.'
                            "
                            :error="form.errors.name"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.name"
                                placeholder="Ex.: Supervisor de atendimento"
                                autocomplete="off"
                                required
                                :readonly="role.is_system"
                                :class="role.is_system && 'bg-neutral-100 text-neutral-600'"
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
                                placeholder="Descreva a finalidade e as responsabilidades deste papel..."
                                :maxlength="limits.description"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @input="clearError('description')"
                            />
                            <p class="mt-1 text-right text-xs text-neutral-500" aria-hidden="true">
                                {{ charCount(form.description, limits.description) }}
                            </p>
                        </FormField>

                        <p class="text-xs text-neutral-500">
                            Salvar não altera as permissões do papel nem os usuários vinculados a ele.
                        </p>
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
