<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
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
import { categoryFormErrors, type CategoryFormField } from '@/lib/category';
import { adminHomeHref } from '@/lib/navigation';
import type { Option } from '@/lib/ticket';
import { useToast } from '@/composables/useToast';

const props = defineProps<{
    statuses: Option[];
    limits: { name: number; description: number };
    submissionToken: string;
}>();

const toast = useToast();
const page = usePage();

/* ---------- Formulário ---------- */

const form = useForm({
    name: '',
    description: '',
    status: 'active',
    submission_token: props.submissionToken,
});


const count = (value: string, max: number) =>
    `${value.length.toLocaleString('pt-BR')} / ${max.toLocaleString('pt-BR')}`;

const validate = (): boolean => {
    const errors = categoryFormErrors(form, props.limits);

    form.clearErrors();
    form.setError(errors);

    return Object.keys(errors).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document.querySelector<HTMLElement>('#category-form [aria-invalid="true"]')?.focus();
};

// Cancelar, menu e sair passam pela confirmação quando há dados preenchidos.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

const submit = () => {
    if (form.processing) return;
    if (!validate()) {
        toast.formErrors();
        focusFirstError();
        return;
    }

    setSubmitting(true);
    form.post(route('admin.categories.store'), {
        preserveScroll: true,
        onError: () => focusFirstError(),
        onFinish: () => setSubmitting(false),
    });
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
    { label: 'Cadastrar' },
]);
</script>

<template>
    <Head title="Nova categoria" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Nova categoria" subtitle="Informe os dados que serão utilizados para classificar os chamados." />
            </div>

            <form id="category-form" novalidate @submit.prevent="submit">
                <Card title="Dados da categoria">
                    <div class="space-y-4">
                        <FormField label="Nome da categoria" required :error="form.errors.name" v-slot="{ id, describedby, invalid }">
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

                        <FormField
                            label="Descrição"
                            help="Opcional."
                            :error="form.errors.description"
                            v-slot="{ id, describedby, invalid }"
                        >
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
                                {{ count(form.description, limits.description) }}
                            </p>
                        </FormField>

                        <StatusRadioGroup
                            v-model="form.status"
                            legend="Situação inicial"
                            :options="statuses"
                            :descriptions="{
                                active: 'Disponível para classificar novos chamados.',
                                inactive: 'Cadastrada, mas não aparece na abertura de chamados.',
                            }"
                            help="Categorias não são excluídas: quando necessário, são inativadas para preservar o histórico dos chamados."
                            :error="form.errors.status"
                            @update:model-value="clearError('status')"
                        />
                    </div>

                    <template #footer>
                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                            <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="cancel">
                                Cancelar
                            </Button>
                            <Button type="submit" class="w-full sm:w-auto" :loading="form.processing">
                                {{ form.processing ? 'Cadastrando…' : 'Cadastrar categoria' }}
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
