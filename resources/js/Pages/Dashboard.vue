<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import BarList from '@/Components/BarList.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PriorityBadge from '@/Components/PriorityBadge.vue';
import Spinner from '@/Components/Spinner.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useListing } from '@/composables/useListing';
import { dashboardFilterKeys, type DashboardFilters, type DashboardMetrics } from '@/lib/dashboard';
import { formatDate, formatDateTime, formatDuration, type TicketFilters } from '@/lib/ticket';

const props = defineProps<{
    filters: DashboardFilters;
    generatedAt: string;
    scope: { allDepartments: boolean; includesAvailable: boolean };
    hasAnyTicket: boolean;
    /** `null` quando nenhum chamado é considerado: não há indicadores a exibir. */
    metrics: DashboardMetrics | null;
    can: { create: boolean };
}>();

/* ---------- Período ---------- */

// O dashboard não tem ordenação: o mesmo valor fixo mantém a URL só com o período.
const NO_SORT = { key: 'none' as const, direction: 'asc' as const };

const { form, serverErrors, hasAppliedFilters, formIsEmpty, processing, loadError, retry, submitFilters, clearFilters } =
    useListing({
        routeName: 'dashboard',
        filterKeys: dashboardFilterKeys,
        filters: () => props.filters,
        sort: () => NO_SORT,
        defaultSort: NO_SORT,
    });

const dateRangeError = computed(() =>
    form.opened_from && form.opened_to && form.opened_to < form.opened_from
        ? 'A data final deve ser igual ou posterior à data inicial.'
        : undefined,
);

const errorFor = (key: string) => (key === 'opened_to' && dateRangeError.value) || serverErrors.value[key];

const apply = () => {
    if (!dateRangeError.value) submitFilters();
};

/** Recarrega os indicadores do período aplicado, descartando alterações não aplicadas no formulário. */
const refresh = () => {
    Object.assign(form, { opened_from: props.filters.opened_from ?? '', opened_to: props.filters.opened_to ?? '' });
    submitFilters();
};

const periodLabel = computed(() => {
    const { opened_from: from, opened_to: to } = props.filters;
    if (from && to) return from === to ? `Abertos em ${formatDate(from)}` : `Abertos de ${formatDate(from)} a ${formatDate(to)}`;
    if (from) return `Abertos a partir de ${formatDate(from)}`;
    if (to) return `Abertos até ${formatDate(to)}`;

    return 'Todo o período';
});

const scopeLabel = computed(() => {
    if (props.scope.allDepartments) return 'Indicadores dos chamados de todos os departamentos.';

    return props.scope.includesAvailable
        ? 'Indicadores dos chamados que você abriu ou pelos quais é responsável, além dos disponíveis para autoatribuição.'
        : 'Indicadores dos chamados que você abriu ou pelos quais é responsável.';
});

/* ---------- Atalhos para a listagem (CHA-01) ---------- */

/** Listagem com o mesmo período e um filtro adicional; o backend reaplica o escopo de acesso. */
const listing = (extra: TicketFilters = {}) => route('tickets.index', { ...props.filters, ...extra });

/* ---------- Indicadores ---------- */

const number = (value: number) => value.toLocaleString('pt-BR');
const plural = (count: number, one: string, many: string) => `${number(count)} ${count === 1 ? one : many}`;

const sla = computed(() => {
    const m = props.metrics!;
    if (!m.sla) return null;

    if (m.sla.tracked === 0) {
        return { value: '—', hint: 'Nenhum chamado considerado tem prazo de SLA registrado.', href: undefined };
    }

    return {
        value: number(m.sla.overdue),
        hint: `Entre ${plural(m.sla.tracked, 'chamado', 'chamados')} com prazo: vencidos em andamento ou resolvidos após o prazo.`,
        href: listing({ sla: 'overdue' }),
    };
});

/** Tempo médio com o número de chamados que entraram na média; sem dado, o texto explica o motivo. */
const averageTime = (stat: { count: number; average_seconds: number | null }, empty: string) =>
    stat.average_seconds === null
        ? { value: '—', hint: empty }
        : { value: formatDuration(stat.average_seconds), hint: `Média de ${plural(stat.count, 'chamado', 'chamados')}.` };

const firstResponseTime = computed(() =>
    averageTime(props.metrics!.times.first_response, 'Nenhum chamado considerado teve a primeira resposta da equipe.'),
);

const resolutionTime = computed(() =>
    averageTime(props.metrics!.times.resolution, 'Nenhum chamado considerado foi resolvido com tempo efetivo registrado.'),
);

const pending = computed(() => {
    const { closed, closed_seconds: seconds, active } = props.metrics!.pending;

    if (closed === 0) {
        return {
            value: 'Nenhuma',
            hint: active
                ? `Nenhuma pendência encerrada; ${plural(active, 'pendência em andamento', 'pendências em andamento')}, ainda sem duração.`
                : 'Nenhuma pendência registrada.',
        };
    }

    const sum = `Soma de ${plural(closed, 'pendência encerrada', 'pendências encerradas')}`;
    // Pendências em andamento ainda não têm duração gravada e não entram na soma.
    const running = active ? `; ${plural(active, 'em andamento não incluída', 'em andamento não incluídas')}` : '';

    return { value: formatDuration(seconds), hint: `${sum}${running}.` };
});
</script>

<template>
    <Head title="Visão Geral" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader title="Visão Geral" :subtitle="scopeLabel">
                <p class="text-xs text-neutral-500">Atualizado em {{ formatDateTime(generatedAt) }}</p>
                <Button variant="secondary" :loading="processing" @click="refresh">
                    <Icon v-if="!processing" name="arrow-path" :size="16" /> Atualizar
                </Button>
            </PageHeader>

            <!-- Período de referência -->
            <Card>
                <form aria-label="Período dos indicadores" @submit.prevent="apply">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto] lg:items-start">
                        <FormField
                            label="Abertura — de"
                            :error="errorFor('opened_from')"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.opened_from"
                                type="date"
                                :max="form.opened_to || undefined"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                            />
                        </FormField>
                        <FormField
                            label="Abertura — até"
                            :error="errorFor('opened_to')"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.opened_to"
                                type="date"
                                :min="form.opened_from || undefined"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                            />
                        </FormField>
                        <div class="flex gap-2 sm:col-span-2 lg:col-span-1 lg:mt-6">
                            <Button type="submit" class="flex-1 lg:flex-none" :disabled="processing || !!dateRangeError">
                                Aplicar
                            </Button>
                            <Button
                                variant="secondary"
                                class="flex-1 lg:flex-none"
                                :disabled="processing || (!hasAppliedFilters && formIsEmpty)"
                                @click="clearFilters"
                            >
                                Todo o período
                            </Button>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ periodLabel }}.</span>
                        O período seleciona os chamados pela data de abertura; status e prioridade são os atuais.
                    </p>
                </form>
            </Card>

            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar os indicadores">
                <p>Verifique sua conexão e tente novamente. Os valores abaixo podem estar desatualizados.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="retry">Tentar novamente</Button>
            </Alert>

            <p class="sr-only" role="status">{{ processing ? 'Atualizando indicadores…' : '' }}</p>

            <div
                class="space-y-6 transition-opacity duration-150 ease-in-out motion-reduce:transition-none"
                :class="processing && 'pointer-events-none opacity-60'"
                :aria-busy="processing || undefined"
            >
                <div v-if="processing" class="flex items-center gap-2 text-sm text-neutral-600" aria-hidden="true">
                    <Spinner /> Atualizando indicadores…
                </div>

                <!-- Sem chamados considerados -->
                <Card v-if="!metrics">
                    <EmptyState
                        v-if="hasAnyTicket"
                        icon="search"
                        title="Nenhum chamado aberto no período"
                        description="Escolha outro período ou consulte todos os chamados do seu escopo."
                    >
                        <Button variant="secondary" :disabled="processing" @click="clearFilters">Ver todo o período</Button>
                    </EmptyState>
                    <EmptyState
                        v-else
                        title="Ainda não há chamados para exibir"
                        description="Os indicadores aparecem quando houver chamados que você possa consultar."
                    >
                        <Button v-if="can.create" :href="route('tickets.create')">
                            <Icon name="plus" :size="16" /> Novo chamado
                        </Button>
                    </EmptyState>
                </Card>

                <template v-else>
                    <!-- Indicadores principais (RF-20) -->
                    <section aria-labelledby="kpis-title">
                        <h2 id="kpis-title" class="sr-only">Indicadores principais</h2>
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <StatCard
                                icon="inbox"
                                label="Total de chamados"
                                :value="number(metrics.total)"
                                :hint="hasAppliedFilters ? 'Abertos no período selecionado.' : 'Todos os chamados do seu escopo.'"
                                :href="listing()"
                            />
                            <StatCard
                                v-if="sla"
                                icon="clock"
                                label="Fora do SLA de resolução"
                                :value="sla.value"
                                :hint="sla.hint"
                                :href="sla.href"
                            />
                            <StatCard
                                icon="arrow-path"
                                label="Chamados reabertos"
                                :value="number(metrics.reopened)"
                                hint="Com ao menos uma reabertura pelo solicitante."
                            />
                            <StatCard icon="pause" label="Tempo total em pendência" :value="pending.value" :hint="pending.hint" />
                            <StatCard
                                icon="clock"
                                label="Tempo médio de primeira resposta"
                                :value="firstResponseTime.value"
                                :hint="firstResponseTime.hint"
                            />
                            <StatCard
                                icon="check-circle"
                                label="Tempo médio de resolução"
                                :value="resolutionTime.value"
                                :hint="resolutionTime.hint"
                            />
                        </div>
                    </section>

                    <!-- Distribuições -->
                    <div class="grid gap-6 lg:grid-cols-2">
                        <Card title="Chamados por status">
                            <BarList
                                :items="metrics.byStatus"
                                :total="metrics.total"
                                :href="(item) => (item.total ? listing({ status: item.value }) : null)"
                                :link-label="(item) => `Ver chamados com status ${item.label}`"
                            >
                                <template #label="{ item }"><StatusBadge :status="item.label" /></template>
                            </BarList>
                        </Card>

                        <Card v-if="metrics.byPriority" title="Chamados por prioridade">
                            <BarList
                                :items="metrics.byPriority"
                                :total="metrics.total"
                                :href="(item) => (item.total ? listing({ priority: item.value }) : null)"
                                :link-label="(item) => `Ver chamados com prioridade ${item.label}`"
                            >
                                <template #label="{ item }"><PriorityBadge :priority="item.label" /></template>
                            </BarList>
                        </Card>

                        <Card title="Chamados por departamento">
                            <BarList
                                :items="metrics.byDepartment.items"
                                :total="metrics.total"
                                :href="(item) => listing({ department: item.value })"
                                :link-label="(item) => `Ver chamados do departamento ${item.label}`"
                            />
                            <p v-if="metrics.byDepartment.others" class="mt-3 px-2 text-xs text-neutral-500">
                                Outros {{ plural(metrics.byDepartment.others.count, 'departamento', 'departamentos') }}:
                                {{ plural(metrics.byDepartment.others.total, 'chamado', 'chamados') }}.
                            </p>
                        </Card>

                        <Card title="Chamados por técnico responsável">
                            <BarList
                                :items="metrics.byAssignee.items"
                                :total="metrics.total"
                                :href="(item) => listing({ assignee: item.value })"
                                :link-label="(item) =>
                                    item.value === 'none' ? 'Ver chamados sem responsável' : `Ver chamados de ${item.label}`"
                            />
                            <p v-if="metrics.byAssignee.others" class="mt-3 px-2 text-xs text-neutral-500">
                                Outros {{ plural(metrics.byAssignee.others.count, 'técnico', 'técnicos') }}:
                                {{ plural(metrics.byAssignee.others.total, 'chamado', 'chamados') }}.
                            </p>
                        </Card>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
