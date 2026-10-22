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
    type SlaPolicyFormField,
    type SlaPriorityOption,
    type TargetLimits,
} from '@/lib/sla';
import type { Option } from '@/lib/ticket';

const props = defineProps<{
    priorities: SlaPriorityOption[];
    statuses: Option[];
    limits: TargetLimits;
    submissionToken: string;
}>();

/* ---------- Formulário ---------- */

// Nenhuma meta vem preenchida: o PRD não define valores padrão.
const form = useForm({
    priority: '',
    first_response_minutes: '',
    resolution_minutes: '',
    status: '',
    submission_token: props.submissionToken,
});

/** Erros que não pertencem a um campo editável (falhas gerais e token de envio). */
const formError = computed(
    () => (form.errors as Record<string, string | undefined>).form ?? (form.errors as Record<string, string | undefined>).submission_token,
);
const unexpectedError = ref(false);
const hasPriorities = computed(() => props.priorities.length > 0);

const selectedPriority = computed(() => props.priorities.find((p) => p.value === form.priority) ?? null);
/** A prioridade escolhida já tem política ativa: só cabe cadastrar esta como inativa. */
const activeConflict = computed(() => !!selectedPriority.value?.has_active && form.status === 'active');

const priorityDescriptions = computed(() =>
    Object.fromEntries(
        props.priorities.map((p) => [p.value, p.has_active ? 'Já possui uma política ativa.' : 'Sem política ativa.']),
    ),
);

const validate = (): boolean => {
    const errors: Partial<Record<SlaPolicyFormField, string>> = slaPolicyFormErrors(form, props.limits);
    if (!errors.status && activeConflict.value) {
        errors.status = `Já existe uma política ativa para a prioridade ${selectedPriority.value!.label}. Cadastre esta como inativa para manter uma única política aplicável.`;
    }

    form.clearErrors();
    form.setError(errors);

    return Object.keys(errors).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document
        .querySelector<HTMLElement>('#sla-policy-form [aria-invalid="true"], #sla-policy-form fieldset[aria-describedby*="error"] input')
        ?.focus();
};

// Cancelar, menu e sair passam pela confirmação quando há dados preenchidos.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

const submit = () => {
    if (form.processing || !hasPriorities.value) return;
    if (!validate()) {
        focusFirstError();
        return;
    }

    unexpectedError.value = false;
    setSubmitting(true);
    form.transform((data) => ({
        ...data,
        first_response_minutes: data.first_response_minutes.trim(),
        resolution_minutes: data.resolution_minutes.trim(),
    })).post(route('sla-policies.store'), {
        preserveScroll: true,
        onError: () => focusFirstError(),
        onFinish: () => setSubmitting(false),
    });
};

const clearError = (field: SlaPolicyFormField) => {
    if (form.errors[field]) form.clearErrors(field);
};

const choosePriority = () => {
    clearError('priority');
    // A situação pode deixar de conflitar (ou passar a conflitar) com a nova prioridade.
    clearError('status');
};

const cancel = () => router.visit(route('sla-policies.index'));

/* ---------- Metas e revisão ---------- */

const targetHelp = (value: string) => {
    const minutes = parseTarget(value, props.limits);

    return minutes === null ? 'Em minutos, tempo corrido.' : `Em minutos, tempo corrido. Equivale a ${formatTarget(minutes)}.`;
};

const review = computed(() => {
    const target = (value: string) => {
        const minutes = parseTarget(value, props.limits);
        if (minutes !== null) return `${formatTarget(minutes)} (${minutes.toLocaleString('pt-BR')} min)`;

        return value.trim() ? 'Valor inválido' : 'Não informada';
    };

    return {
        priority: selectedPriority.value,
        firstResponse: target(form.first_response_minutes),
        resolution: target(form.resolution_minutes),
        status: props.statuses.find((s) => s.value === form.status)?.label ?? null,
    };
});

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

const breadcrumbs = [
    { label: 'Início', href: route('dashboard') },
    { label: 'SLA e pendências' },
    { label: 'Políticas de SLA', href: route('sla-policies.index') },
    { label: 'Criar política' },
];
</script>

<template>
    <Head title="Criar política de SLA" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Criar política de SLA" subtitle="Configure as metas de atendimento por prioridade.">
                    <Button variant="secondary" :href="route('sla-policies.index')">
                        <Icon name="chevron-left" :size="16" /> Voltar à listagem
                    </Button>
                </PageHeader>
            </div>

            <Alert v-if="!hasPriorities" variant="warning" title="Nenhuma prioridade disponível">
                Não há prioridades cadastradas no sistema, então não é possível definir metas. Nenhuma política foi criada.
            </Alert>

            <form v-else id="sla-policy-form" class="space-y-6" novalidate @submit.prevent="submit">
                <Alert v-if="unexpectedError" variant="danger" title="Não foi possível cadastrar a política">
                    Ocorreu uma falha inesperada e nada foi gravado. Verifique sua conexão e tente novamente.
                </Alert>

                <Alert v-if="formError" variant="danger" title="Não foi possível cadastrar a política">
                    {{ formError }}
                </Alert>

                <!-- A: prioridade e situação -->
                <Card title="Informações gerais">
                    <div class="space-y-6">
                        <StatusRadioGroup
                            v-model="form.priority"
                            legend="Prioridade"
                            :options="priorities"
                            :descriptions="priorityDescriptions"
                            help="Cada política define as metas de uma prioridade. Só pode haver uma política ativa por prioridade."
                            :error="form.errors.priority"
                            @update:model-value="choosePriority"
                        />

                        <StatusRadioGroup
                            v-model="form.status"
                            legend="Situação inicial"
                            :options="statuses"
                            :descriptions="{
                                active: 'Metas em vigor para a prioridade.',
                                inactive: 'Cadastrada sem entrar em vigor.',
                            }"
                            help="Cadastrar a política não altera os chamados existentes."
                            :error="form.errors.status"
                            @update:model-value="clearError('status')"
                        />

                        <Alert v-if="activeConflict && !form.errors.status" variant="warning">
                            A prioridade {{ selectedPriority!.label }} já possui uma política ativa. Para manter uma única
                            política aplicável, cadastre esta como inativa.
                        </Alert>
                    </div>
                </Card>

                <!-- B: metas -->
                <Card title="Metas de atendimento">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Meta de primeira resposta"
                            required
                            :help="targetHelp(form.first_response_minutes)"
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
                            :help="targetHelp(form.resolution_minutes)"
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
                    <p class="mt-4 text-xs text-neutral-500">
                        Primeira resposta: até o primeiro comentário público do suporte. Resolução: tempo efetivo de atendimento,
                        sem contar as pausas de espera. Sem calendário de horário comercial.
                    </p>
                </Card>

                <!-- C: revisão -->
                <Card title="Revisão">
                    <dl class="grid gap-4 text-sm sm:grid-cols-2" aria-live="polite">
                        <div>
                            <dt class="text-xs font-medium text-neutral-500">Prioridade</dt>
                            <dd class="mt-1">
                                <PriorityBadge v-if="review.priority" :priority="review.priority.label" />
                                <span v-else class="text-neutral-500">Não selecionada</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-neutral-500">Situação inicial</dt>
                            <dd class="mt-1">
                                <Badge v-if="review.status" :tone="form.status === 'active' ? 'success' : 'neutral'">{{ review.status }}</Badge>
                                <span v-else class="text-neutral-500">Não selecionada</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-neutral-500">Meta de primeira resposta</dt>
                            <dd class="mt-1 text-neutral-900">{{ review.firstResponse }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-neutral-500">Meta de resolução</dt>
                            <dd class="mt-1 text-neutral-900">{{ review.resolution }}</dd>
                        </div>
                    </dl>

                    <template #footer>
                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                            <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="cancel">
                                Cancelar
                            </Button>
                            <Button type="submit" class="w-full sm:w-auto" :loading="form.processing">
                                {{ form.processing ? 'Salvando…' : 'Salvar política' }}
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
