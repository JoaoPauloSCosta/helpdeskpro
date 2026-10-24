<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PriorityBadge from '@/Components/PriorityBadge.vue';
import StatusRadioGroup from '@/Components/StatusRadioGroup.vue';
import TextInput from '@/Components/TextInput.vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    formatTarget,
    parseTarget,
    slaPolicyFormErrors,
    type SlaPolicyEditTarget,
    type SlaPolicyEditValues,
    type SlaPolicyFormField,
    type TargetLimits,
} from '@/lib/sla';
import type { Option } from '@/lib/ticket';
import { formatShortDate } from '@/lib/user';
import { useToast } from '@/composables/useToast';

const props = defineProps<{
    policy: SlaPolicyEditTarget;
    values: SlaPolicyEditValues;
    statuses: Option[];
    limits: TargetLimits;
    version: string;
}>();

const toast = useToast();

/* ---------- Formulário ---------- */

const form = useForm({ ...props.values, version: props.version });

const deactivating = computed(() => props.policy.is_active && form.status === 'inactive');
const activating = computed(() => !props.policy.is_active && form.status === 'active');
/** Ativar daria duas políticas ativas à mesma prioridade (RF-15.3); o backend também recusa. */
const activationBlocked = computed(() => activating.value && props.policy.other_active);
const activationConflictMessage = computed(
    () =>
        `Já existe outra política ativa para a prioridade ${props.policy.priority}. Inative-a antes de ativar esta, para manter uma única política aplicável.`,
);

const otherError = (key: 'conflict' | 'version') => (form.errors as Record<string, string | undefined>)[key];


const validate = (): boolean => {
    const errors: Partial<Record<Exclude<SlaPolicyFormField, 'priority'>, string>> = slaPolicyFormErrors(form, props.limits);
    if (!errors.status && activationBlocked.value) errors.status = activationConflictMessage.value;

    form.clearErrors();
    form.setError(errors);

    return Object.keys(errors).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document
        .querySelector<HTMLElement>('#sla-policy-edit-form [aria-invalid="true"], #sla-policy-edit-form fieldset[aria-describedby*="error"] input')
        ?.focus();
};

// Cancelar, menu e sair passam pela confirmação quando há alterações não salvas.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

const confirmStatusOpen = ref(false);

const send = () => {
    confirmStatusOpen.value = false;
    setSubmitting(true);

    form.transform((data) => ({
        ...data,
        first_response_minutes: data.first_response_minutes.trim(),
        resolution_minutes: data.resolution_minutes.trim(),
    })).patch(route('sla-policies.update', props.policy.id), {
        preserveScroll: true,
        onError: () => focusFirstError(),
        onFinish: () => setSubmitting(false),
    });
};

const submit = () => {
    if (form.processing) return;

    if (changes.value.length === 0) {
        toast.info('Nenhuma alteração para salvar.');
        return;
    }
    if (!validate()) {
        toast.formErrors();
        focusFirstError();
        return;
    }

    // Mudança de situação altera qual política vale para a prioridade: pede confirmação.
    if (deactivating.value || activating.value) confirmStatusOpen.value = true;
    else send();
};

/** Descarta o que foi digitado e carrega os valores atuais do servidor. */
const reloadCurrent = () => {
    form.reset();
    router.visit(route('sla-policies.edit', props.policy.id), { preserveState: false });
};

const clearError = (field: Exclude<SlaPolicyFormField, 'priority'>) => {
    if (form.errors[field]) form.clearErrors(field);
};

const cancel = () => router.visit(route('sla-policies.index'));

/* ---------- Metas e revisão ---------- */

const describeTarget = (minutes: number) => `${formatTarget(minutes)} (${minutes.toLocaleString('pt-BR')} min)`;

const targetHelp = (value: string, saved: number) => {
    const minutes = parseTarget(value, props.limits);
    const current = `Valor salvo: ${describeTarget(saved)}.`;

    return minutes === null || minutes === saved ? `Em minutos, tempo corrido. ${current}` : `Equivale a ${formatTarget(minutes)}. ${current}`;
};

const statusLabel = (active: boolean) => (active ? 'Ativa' : 'Inativa');

/** Alterações reais em relação aos valores salvos, na ordem do formulário. */
const changes = computed(() => {
    const list: { label: string; from: string; to: string }[] = [];
    const target = (label: string, value: string, saved: number) => {
        const text = value.trim();
        if (text === String(saved)) return;

        const minutes = parseTarget(text, props.limits);
        list.push({ label, from: describeTarget(saved), to: minutes === null ? `${text || 'vazio'} (inválido)` : describeTarget(minutes) });
    };

    target('Meta de primeira resposta', form.first_response_minutes, props.policy.first_response_minutes);
    target('Meta de resolução', form.resolution_minutes, props.policy.resolution_minutes);
    if (form.status !== props.values.status) {
        list.push({ label: 'Situação', from: statusLabel(props.policy.is_active), to: statusLabel(form.status === 'active') });
    }

    return list;
});

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

const breadcrumbs = [
    { label: 'Início', href: route('dashboard') },
    { label: 'SLA e pendências' },
    { label: 'Políticas de SLA', href: route('sla-policies.index') },
    { label: 'Editar política' },
];
</script>

<template>
    <Head :title="`Editar política de SLA — ${policy.priority}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Editar política de SLA" subtitle="Atualize as metas e a situação da política.">
                    <Button variant="secondary" :href="route('sla-policies.index')">
                        <Icon name="chevron-left" :size="16" /> Voltar à listagem
                    </Button>
                </PageHeader>
            </div>

            <!-- Identificação da política selecionada -->
            <Card>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0 space-y-1">
                        <p class="text-sm text-neutral-600">
                            Política da prioridade <PriorityBadge :priority="policy.priority" class="ml-1" />
                        </p>
                        <p class="text-xs text-neutral-500">
                            Criada em {{ formatShortDate(policy.created_at) }} · Atualizada em {{ formatShortDate(policy.updated_at) }}
                        </p>
                    </div>
                    <Badge :tone="policy.is_active ? 'success' : 'neutral'" class="self-start sm:self-center">
                        {{ statusLabel(policy.is_active) }}
                    </Badge>
                </div>
                <p class="mt-3 text-xs text-neutral-500">
                    A prioridade identifica a política e não é editável. Para outra prioridade, cadastre uma nova política.
                </p>
            </Card>

            <form id="sla-policy-edit-form" class="space-y-6" novalidate @submit.prevent="submit">
                <Alert v-if="otherError('conflict')" variant="danger" title="A política foi alterada">
                    <p>{{ otherError('conflict') }}</p>
                    <Button variant="secondary" size="sm" class="mt-3" @click="reloadCurrent">Recarregar dados atuais</Button>
                </Alert>

                <!-- Metas -->
                <Card title="Metas de atendimento">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Meta de primeira resposta"
                            required
                            :help="targetHelp(form.first_response_minutes, policy.first_response_minutes)"
                            :error="form.errors.first_response_minutes"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.first_response_minutes"
                                inputmode="numeric"
                                autocomplete="off"
                                placeholder="Minutos"
                                required
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @input="clearError('first_response_minutes')"
                            />
                        </FormField>
                        <FormField
                            label="Meta de resolução"
                            required
                            :help="targetHelp(form.resolution_minutes, policy.resolution_minutes)"
                            :error="form.errors.resolution_minutes"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.resolution_minutes"
                                inputmode="numeric"
                                autocomplete="off"
                                placeholder="Minutos"
                                required
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @input="clearError('resolution_minutes')"
                            />
                        </FormField>
                    </div>
                    <p class="mt-4 text-xs text-neutral-500">Salvar não altera os chamados existentes.</p>
                </Card>

                <!-- Situação -->
                <Card title="Situação">
                    <StatusRadioGroup
                        v-model="form.status"
                        :options="statuses"
                        :descriptions="{
                            active: 'Metas em vigor para a prioridade.',
                            inactive: 'Mantida para consulta, sem estar em vigor.',
                        }"
                        help="Situação cadastral da política, não o cumprimento do SLA de um chamado. Políticas não são excluídas."
                        :error="form.errors.status"
                        @update:model-value="clearError('status')"
                    >
                        <Alert v-if="deactivating" variant="warning" title="A política será inativada" class="mt-4">
                            A prioridade {{ policy.priority }} ficará sem política ativa até que outra seja ativada. A política
                            continua cadastrada e pode ser reativada depois.
                        </Alert>
                        <Alert v-if="activationBlocked && !form.errors.status" variant="warning" title="Ativação indisponível" class="mt-4">
                            {{ activationConflictMessage }}
                        </Alert>
                        <Alert v-else-if="activating && !activationBlocked" variant="info" class="mt-4">
                            Ao salvar, esta passa a ser a política ativa da prioridade {{ policy.priority }}.
                        </Alert>
                    </StatusRadioGroup>
                </Card>

                <!-- Revisão -->
                <Card title="Revisão das alterações">
                    <ul v-if="changes.length" class="divide-y divide-neutral-200 text-sm" aria-live="polite">
                        <li v-for="change in changes" :key="change.label" class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0 sm:flex-row sm:justify-between">
                            <span class="font-medium text-neutral-700">{{ change.label }}</span>
                            <span class="text-neutral-600">
                                {{ change.from }} <span aria-hidden="true">→</span><span class="sr-only">para</span>
                                <span class="font-medium text-neutral-900">{{ change.to }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-neutral-500" aria-live="polite">Nenhuma alteração em relação aos valores salvos.</p>

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
            :show="confirmStatusOpen"
            :title="deactivating ? 'Inativar esta política?' : 'Ativar esta política?'"
            :message="
                deactivating
                    ? `A prioridade ${policy.priority} ficará sem política ativa. A política continua cadastrada e pode ser reativada depois. Os chamados existentes não são alterados.`
                    : `Esta passará a ser a política ativa da prioridade ${policy.priority}. Os chamados existentes não são alterados.`
            "
            :confirm-label="deactivating ? 'Inativar e salvar' : 'Ativar e salvar'"
            cancel-label="Voltar"
            :variant="deactivating ? 'danger' : 'primary'"
            @close="confirmStatusOpen = false"
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
