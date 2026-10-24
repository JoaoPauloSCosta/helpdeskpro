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
    categoryFormErrors,
    type CategoryEditTarget,
    type CategoryFormField,
    type CategoryFormValues,
} from '@/lib/category';
import { adminHomeHref } from '@/lib/navigation';
import type { Option } from '@/lib/ticket';
import { formatShortDate } from '@/lib/user';
import { useToast } from '@/composables/useToast';

const props = defineProps<{
    category: CategoryEditTarget;
    values: CategoryFormValues;
    statuses: Option[];
    limits: { name: number; description: number };
    version: string;
}>();

const toast = useToast();
const page = usePage();

/* ---------- Formulário ---------- */

const form = useForm({ ...props.values, version: props.version });

/** Inativação pedida neste envio: pede confirmação antes de salvar. */
const deactivating = computed(() => props.category.is_active && form.status === 'inactive');
const reactivating = computed(() => !props.category.is_active && form.status === 'active');

const otherError = (key: 'conflict' | 'version') => (form.errors as Record<string, string | undefined>)[key];


const ticketCount = computed(
    () => `${props.category.tickets_count.toLocaleString('pt-BR')} ${props.category.tickets_count === 1 ? 'chamado' : 'chamados'}`,
);
const charCount = (value: string, max: number) =>
    `${value.length.toLocaleString('pt-BR')} / ${max.toLocaleString('pt-BR')}`;

const validate = (): boolean => {
    const errors = categoryFormErrors(form, props.limits);

    form.clearErrors();
    form.setError(errors);

    return Object.keys(errors).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document.querySelector<HTMLElement>('#category-edit-form [aria-invalid="true"]')?.focus();
};

// Cancelar, menu e sair passam pela confirmação quando há alterações não salvas.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

const confirmDeactivationOpen = ref(false);

const send = () => {
    confirmDeactivationOpen.value = false;
    setSubmitting(true);

    form.patch(route('admin.categories.update', props.category.id), {
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
    router.visit(route('admin.categories.edit', props.category.id), { preserveState: false });
};

const clearError = (field: CategoryFormField) => {
    if (form.errors[field]) form.clearErrors(field);
};

const cancel = () => router.visit(route('admin.categories.index'));

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
    { label: 'Categorias', href: route('admin.categories.index') },
    { label: 'Editar' },
]);
</script>

<template>
    <Head :title="`Editar ${category.name}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Editar categoria" subtitle="Atualize as informações da categoria selecionada." />
            </div>

            <!-- Identificação da categoria selecionada -->
            <Card>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="break-words text-base font-medium text-neutral-900">{{ category.name }}</p>
                        <p class="mt-1 text-xs text-neutral-500">
                            {{ ticketCount }} · Criada em {{ formatShortDate(category.created_at) }} · Atualizada em
                            {{ formatShortDate(category.updated_at) }}
                        </p>
                    </div>
                    <Badge :tone="category.is_active ? 'success' : 'neutral'" class="self-start sm:self-center">
                        {{ category.is_active ? 'Ativa' : 'Inativa' }}
                    </Badge>
                </div>
            </Card>

            <form id="category-edit-form" class="space-y-6" novalidate @submit.prevent="submit">
                <Alert v-if="otherError('conflict')" variant="danger" title="A categoria foi alterada">
                    <p>{{ otherError('conflict') }}</p>
                    <Button variant="secondary" size="sm" class="mt-3" @click="reloadCurrent">Recarregar dados atuais</Button>
                </Alert>

                <Card title="Dados da categoria">
                    <div class="space-y-4">
                        <FormField
                            label="Nome da categoria"
                            required
                            help="Renomear não altera os chamados: o histórico mantém o nome da época de cada evento."
                            :error="form.errors.name"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.name"
                                placeholder="Ex.: Hardware"
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
                                placeholder="Descreva quais solicitações ou problemas pertencem a esta categoria..."
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
                            active: 'Disponível para classificar novos chamados.',
                            inactive: 'Não aparece na abertura de chamados nem como nova opção de classificação.',
                        }"
                        help="Categorias não são excluídas: a inativação preserva os chamados e o histórico."
                        :error="form.errors.status"
                        @update:model-value="clearError('status')"
                    >
                        <Alert v-if="deactivating" variant="warning" title="A categoria será inativada" class="mt-4">
                            Ela deixará de ser oferecida na abertura de chamados e na troca de categoria. Os chamados já
                            classificados nela ({{ ticketCount }}) continuam vinculados e exibem a categoria normalmente.
                        </Alert>
                        <Alert v-if="reactivating" variant="info" class="mt-4">
                            Ao salvar, a categoria volta a ficar disponível para classificar novos chamados.
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
            title="Inativar esta categoria?"
            :message="`${category.name} não poderá ser usada em novos chamados. Os chamados e o histórico continuam preservados, e a categoria pode ser reativada depois.`"
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
