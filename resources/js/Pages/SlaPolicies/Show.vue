<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Badge.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PriorityBadge from '@/Components/PriorityBadge.vue';
import Timeline from '@/Components/Timeline.vue';
import TimelineItem from '@/Components/TimelineItem.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    formatTarget,
    type SlaPolicyDetail,
    type SlaPolicyHistoryEvent,
    type SlaPolicyHistoryField,
    type SlaPolicySibling,
} from '@/lib/sla';
import { formatDateTime } from '@/lib/ticket';

defineProps<{
    policy: SlaPolicyDetail;
    activeSibling: SlaPolicySibling | null;
    /** Nulo quando o usuário não tem `audit.view`: a seção não é exibida. */
    history: SlaPolicyHistoryEvent[] | null;
    can: { edit: boolean };
}>();

const describeTarget = (minutes: number) => `${formatTarget(minutes)} (${minutes.toLocaleString('pt-BR')} min)`;
const statusLabel = (active: boolean) => (active ? 'Ativa' : 'Inativa');

const fieldLabels: Record<SlaPolicyHistoryField, string> = {
    first_response_minutes: 'Primeira resposta',
    resolution_minutes: 'Resolução',
    is_active: 'Situação',
};

const formatValue = (field: SlaPolicyHistoryField, value: number | boolean | null): string => {
    if (value === null) return '—';
    if (field === 'is_active') return statusLabel(value as boolean);

    return formatTarget(value as number);
};

const eventTone = (event: SlaPolicyHistoryEvent['event']) =>
    event === 'sla_policy_deactivated' ? 'neutral' : event === 'sla_policy_activated' ? 'success' : 'primary';

const breadcrumbs = [
    { label: 'Início', href: route('dashboard') },
    { label: 'SLA e pendências' },
    { label: 'Políticas de SLA', href: route('sla-policies.index') },
    { label: 'Detalhes da política' },
];
</script>

<template>
    <Head :title="`Política de SLA — ${policy.priority}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Detalhes da política de SLA" subtitle="Consulte os parâmetros configurados para esta política.">
                    <Button variant="secondary" :href="route('sla-policies.index')">
                        <Icon name="chevron-left" :size="16" /> Voltar à listagem
                    </Button>
                    <Button v-if="can.edit" :href="route('sla-policies.edit', policy.id)">
                        <Icon name="pencil" :size="16" /> Editar
                    </Button>
                </PageHeader>
            </div>

            <!-- Identificação -->
            <Card>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium text-neutral-500">Prioridade</dt>
                        <dd class="mt-1"><PriorityBadge :priority="policy.priority" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-neutral-500">Situação</dt>
                        <dd class="mt-1">
                            <Badge :tone="policy.is_active ? 'success' : 'neutral'">
                                <Icon :name="policy.is_active ? 'check-circle' : 'pause'" :size="16" />
                                {{ statusLabel(policy.is_active) }}
                            </Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-neutral-500">Criada em</dt>
                        <dd class="mt-1 text-sm text-neutral-900">{{ formatDateTime(policy.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-neutral-500">Última atualização</dt>
                        <dd class="mt-1 text-sm text-neutral-900">{{ formatDateTime(policy.updated_at) }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-neutral-500">A situação é cadastral: não indica o cumprimento do SLA de nenhum chamado.</p>
            </Card>

            <!-- Metas: as duas do modelo (RF-15), em minutos corridos -->
            <Card title="Metas de atendimento">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-md border border-neutral-200 p-4">
                        <dt class="text-sm text-neutral-600">Primeira resposta</dt>
                        <dd class="mt-1 text-2xl font-semibold text-neutral-900">{{ formatTarget(policy.first_response_minutes) }}</dd>
                        <dd class="mt-1 text-xs text-neutral-500">
                            {{ policy.first_response_minutes.toLocaleString('pt-BR') }} minutos até o primeiro comentário público do suporte.
                        </dd>
                    </div>
                    <div class="rounded-md border border-neutral-200 p-4">
                        <dt class="text-sm text-neutral-600">Resolução</dt>
                        <dd class="mt-1 text-2xl font-semibold text-neutral-900">{{ formatTarget(policy.resolution_minutes) }}</dd>
                        <dd class="mt-1 text-xs text-neutral-500">
                            {{ policy.resolution_minutes.toLocaleString('pt-BR') }} minutos de atendimento efetivo, sem contar as pausas de espera.
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-neutral-500">Metas definidas em tempo corrido, sem calendário de horário comercial.</p>
            </Card>

            <!-- Aplicação: a única regra implementada é uma política ativa por prioridade -->
            <Card title="Aplicação na prioridade">
                <div class="space-y-3 text-sm text-neutral-700">
                    <p v-if="policy.is_active">
                        Esta é a política ativa da prioridade <strong class="font-medium text-neutral-900">{{ policy.priority }}</strong>.
                        Só pode haver uma política ativa por prioridade.
                    </p>
                    <template v-else-if="activeSibling">
                        <p>
                            Esta política está inativa. A política ativa da prioridade
                            <strong class="font-medium text-neutral-900">{{ policy.priority }}</strong> define
                            {{ describeTarget(activeSibling.first_response_minutes) }} para a primeira resposta e
                            {{ describeTarget(activeSibling.resolution_minutes) }} para a resolução.
                        </p>
                        <Link
                            :href="route('sla-policies.show', activeSibling.id)"
                            class="inline-flex items-center gap-1 rounded font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        >
                            Ver a política ativa <Icon name="chevron-right" :size="16" />
                        </Link>
                    </template>
                    <p v-else>
                        Esta política está inativa e a prioridade <strong class="font-medium text-neutral-900">{{ policy.priority }}</strong>
                        não tem nenhuma política ativa no momento.
                    </p>
                </div>
            </Card>

            <!-- Histórico: só com audit.view -->
            <Card v-if="history" title="Histórico de alterações" :flush="history.length === 0">
                <EmptyState
                    v-if="history.length === 0"
                    icon="clock"
                    title="Nenhum evento registrado"
                    description="Não há registros de auditoria para esta política."
                />
                <Timeline v-else>
                    <TimelineItem
                        v-for="item in history"
                        :key="item.id"
                        :title="item.title"
                        :author="item.author ?? undefined"
                        :date="formatDateTime(item.created_at)"
                        :tone="eventTone(item.event)"
                    >
                        <ul v-if="item.changes.length" class="space-y-0.5">
                            <li v-for="change in item.changes" :key="change.field" class="break-words">
                                {{ fieldLabels[change.field] }}:
                                <template v-if="item.event === 'sla_policy_created'">{{ formatValue(change.field, change.to) }}</template>
                                <template v-else>
                                    {{ formatValue(change.field, change.from) }} <span aria-hidden="true">→</span
                                    ><span class="sr-only">para</span> {{ formatValue(change.field, change.to) }}
                                </template>
                            </li>
                        </ul>
                    </TimelineItem>
                </Timeline>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
