<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Select from '@/Components/Select.vue';
import Textarea from '@/Components/Textarea.vue';
import TextInput from '@/Components/TextInput.vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AttachmentPicker from '@/Components/AttachmentPicker.vue';
import type { AttachmentLimits } from '@/lib/attachments';
import type { Option, TicketRequester } from '@/lib/ticket';
import { useToast } from '@/composables/useToast';

const props = defineProps<{
    categories: Option[];
    requester: TicketRequester;
    limits: { title: number; description: number };
    attachmentLimits: AttachmentLimits;
    submissionToken: string;
}>();

/* ---------- Formulário ---------- */

// Somente o que o solicitante informa. Autor, departamento, status e identificador ficam no backend.
const toast = useToast();
const form = useForm({
    title: '',
    description: '',
    category_id: '',
    attachments: [] as File[],
    submission_token: props.submissionToken,
});

type Field = 'title' | 'description' | 'category_id';

/** Erros do servidor para os anexos: do campo e de cada arquivo (`attachments.0`, ...). */
const attachmentErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key === 'attachments' || key.startsWith('attachments.'))
        .map(([, message]) => message),
);

const hasCategories = computed(() => props.categories.length > 0);
const canSubmit = computed(() => hasCategories.value && props.requester.departmentActive);

/** Erros que não pertencem a um campo editável (departamento, falhas gerais e token de envio). */
const otherError = (key: 'department' | 'form' | 'submission_token') =>
    (form.errors as Record<string, string | undefined>)[key];


const count = (value: string, max: number) =>
    `${value.length.toLocaleString('pt-BR')} / ${max.toLocaleString('pt-BR')}`;

/** Mesmas regras do StoreTicketRequest; o servidor valida tudo de novo. */
const validate = (): boolean => {
    const errors: Partial<Record<Field, string>> = {};

    if (!form.title.trim()) errors.title = 'Informe o título do chamado.';
    else if (form.title.length > props.limits.title)
        errors.title = `O título pode ter no máximo ${props.limits.title} caracteres.`;

    if (!form.description.trim()) errors.description = 'Descreva a sua solicitação.';
    else if (form.description.length > props.limits.description)
        errors.description = `A descrição pode ter no máximo ${props.limits.description.toLocaleString('pt-BR')} caracteres.`;

    if (!form.category_id) errors.category_id = 'Selecione uma categoria.';

    form.clearErrors();
    form.setError(errors);

    return Object.keys(errors).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document.querySelector<HTMLElement>('#ticket-form [aria-invalid="true"]')?.focus();
};

// Cancelar, menu e sair passam pela confirmação quando há dados preenchidos.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

const submit = () => {
    if (form.processing || !canSubmit.value) return;
    if (!validate()) {
        toast.formErrors();
        focusFirstError();
        return;
    }

    setSubmitting(true);
    form.post(route('tickets.store'), {
        preserveScroll: true,
        onError: () => focusFirstError(),
        onFinish: () => setSubmitting(false),
    });
};

const clearError = (field: Field) => {
    if (form.errors[field]) form.clearErrors(field);
};

const cancel = () => router.visit(route('tickets.index'));

/* ---------- Dicas ---------- */

const tipsOpen = ref(true);

const tips = [
    ['Use um título claro e objetivo.', 'Exemplo: “Computador não conecta à rede”.'],
    ['Descreva o que está acontecendo.', 'Inclua mensagens de erro, o comportamento observado e quando o problema começou.'],
    ['Informe o impacto.', 'Explique como o problema afeta o seu trabalho.'],
    ['Anexe evidências, se disponível.', 'Imagens e documentos ajudam a equipe de suporte quando o envio de anexos estiver habilitado.'],
];
</script>

<template>
    <Head title="Novo chamado" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader title="Novo chamado" subtitle="Registre uma nova solicitação para a equipe de suporte." />

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Formulário (vem antes dos painéis também no celular) -->
                <form id="ticket-form" class="min-w-0 lg:col-span-2" novalidate @submit.prevent="submit">
                    <Card title="Informações do chamado">
                        <div class="space-y-4">
                            <Alert v-if="!requester.departmentActive" variant="warning" title="Departamento não disponível">
                                Seu cadastro não possui um departamento ativo, e todo chamado precisa estar vinculado
                                a um. Procure o administrador do sistema para regularizar o cadastro.
                            </Alert>

                            <Alert v-if="!hasCategories" variant="warning" title="Nenhuma categoria disponível">
                                Ainda não há categorias ativas para novos chamados. Procure o administrador do sistema.
                            </Alert>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <FormField
                                        label="Departamento"
                                        required
                                        help="Definido pelo seu cadastro. Para alterá-lo, procure o administrador."
                                        :error="otherError('department')"
                                        v-slot="{ id, describedby, invalid }"
                                    >
                                        <TextInput
                                            :id="id"
                                            :model-value="requester.department ?? 'Não informado'"
                                            readonly
                                            class="bg-neutral-100 text-neutral-700"
                                            :aria-describedby="describedby"
                                            :invalid="invalid"
                                        />
                                    </FormField>
                                </div>

                                <FormField label="Categoria" required :error="form.errors.category_id" v-slot="{ id, describedby, invalid }">
                                    <Select
                                        :id="id"
                                        v-model="form.category_id"
                                        placeholder="Selecione uma categoria"
                                        required
                                        :disabled="!hasCategories"
                                        :aria-describedby="describedby"
                                        :invalid="invalid"
                                        @change="clearError('category_id')"
                                    >
                                        <option v-for="o in categories" :key="o.value" :value="o.value">{{ o.label }}</option>
                                    </Select>
                                </FormField>

                                <div class="sm:col-span-2">
                                    <FormField label="Título" required :error="form.errors.title" v-slot="{ id, describedby, invalid }">
                                        <TextInput
                                            :id="id"
                                            v-model="form.title"
                                            placeholder="Informe um título claro e objetivo para sua solicitação"
                                            required
                                            :maxlength="limits.title"
                                            :aria-describedby="describedby"
                                            :invalid="invalid"
                                            @input="clearError('title')"
                                        />
                                        <p class="mt-1 text-right text-xs text-neutral-500" aria-hidden="true">
                                            {{ count(form.title, limits.title) }}
                                        </p>
                                    </FormField>
                                </div>

                                <div class="sm:col-span-2">
                                    <FormField
                                        label="Descrição detalhada"
                                        required
                                        :error="form.errors.description"
                                        v-slot="{ id, describedby, invalid }"
                                    >
                                        <Textarea
                                            :id="id"
                                            v-model="form.description"
                                            :rows="8"
                                            placeholder="Descreva sua solicitação com o máximo de detalhes possível. Inclua o que está acontecendo, quando começou e qual o impacto."
                                            required
                                            :maxlength="limits.description"
                                            :aria-describedby="describedby"
                                            :invalid="invalid"
                                            @input="clearError('description')"
                                        />
                                        <p class="mt-1 text-right text-xs text-neutral-500" aria-hidden="true">
                                            {{ count(form.description, limits.description) }}
                                        </p>
                                    </FormField>
                                </div>

                                <!-- Anexos da abertura (RF-23): públicos, conferidos no servidor. -->
                                <div class="sm:col-span-2">
                                    <AttachmentPicker
                                        v-model="form.attachments"
                                        :limits="attachmentLimits"
                                        :errors="attachmentErrors"
                                        :disabled="form.processing"
                                    />
                                </div>
                            </div>
                        </div>

                        <template #footer>
                            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="cancel">
                                    Cancelar
                                </Button>
                                <Button
                                    type="submit"
                                    class="w-full sm:w-auto"
                                    :loading="form.processing"
                                    :disabled="!canSubmit"
                                >
                                    {{ form.processing ? 'Abrindo chamado…' : 'Abrir chamado' }}
                                </Button>
                            </div>
                        </template>
                    </Card>
                </form>

                <!-- Painéis auxiliares -->
                <div class="min-w-0 space-y-6">
                    <Card title="Dados do solicitante">
                        <dl class="space-y-3 text-sm">
                            <div>
                                <dt class="text-xs font-medium text-neutral-500">Nome</dt>
                                <dd class="break-words text-neutral-900">{{ requester.name }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-neutral-500">E-mail</dt>
                                <dd class="break-words text-neutral-900">{{ requester.email }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-neutral-500">Departamento</dt>
                                <dd v-if="requester.department" class="break-words text-neutral-900">
                                    {{ requester.department }}
                                    <span v-if="!requester.departmentActive" class="text-neutral-500">(inativo)</span>
                                </dd>
                                <dd v-else class="text-neutral-500">Não informado</dd>
                            </div>
                        </dl>
                        <p class="mt-4 text-xs text-neutral-500">
                            O chamado é registrado em seu nome e no departamento do seu cadastro.
                        </p>
                    </Card>

                    <Card>
                        <template #header>
                            <h2 class="text-lg font-semibold text-neutral-900">
                                <button
                                    type="button"
                                    class="-mx-1 flex items-center gap-2 rounded-md px-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                    aria-controls="ticket-tips"
                                    :aria-expanded="tipsOpen"
                                    @click="tipsOpen = !tipsOpen"
                                >
                                    Dicas para um bom chamado
                                    <Icon
                                        name="chevron-down"
                                        :size="16"
                                        class="transition-transform duration-150 motion-reduce:transition-none"
                                        :class="tipsOpen && 'rotate-180'"
                                    />
                                </button>
                            </h2>
                        </template>
                        <ol v-show="tipsOpen" id="ticket-tips" class="space-y-3 text-sm">
                            <li v-for="([title, text], index) in tips" :key="title" class="flex gap-3">
                                <span
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-50 text-xs font-semibold text-primary-700"
                                    aria-hidden="true"
                                >
                                    {{ index + 1 }}
                                </span>
                                <p class="text-neutral-600">
                                    <span class="font-medium text-neutral-900">{{ title }}</span>
                                    {{ text }}
                                </p>
                            </li>
                        </ol>
                        <p v-show="!tipsOpen" class="text-sm text-neutral-500">Dicas ocultas.</p>
                    </Card>
                </div>
            </div>
        </div>

        <ConfirmModal
            :show="leaveOpen"
            title="Descartar o chamado?"
            message="As informações preenchidas ainda não foram enviadas e serão perdidas."
            confirm-label="Descartar e sair"
            cancel-label="Continuar editando"
            variant="danger"
            @close="stayOnPage"
            @confirm="confirmLeave"
        />
    </AuthenticatedLayout>
</template>
