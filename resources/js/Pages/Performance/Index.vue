<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import BarList from '@/Components/BarList.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import Select from '@/Components/Select.vue';
import Spinner from '@/Components/Spinner.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useListing } from '@/composables/useListing';
import EvolutionChart from '@/Pages/Performance/Partials/EvolutionChart.vue';
import {
    percent,
    performanceFilterKeys,
    type PerformanceFilters,
    type PerformanceOptions,
    type PerformancePeriod,
    type PerformanceReport,
} from '@/lib/performance';
import { formatDate, formatDateTime, formatDuration, type TicketStatus } from '@/lib/ticket';

const props = defineProps<{
    filters: PerformanceFilters;
    period: PerformancePeriod;
    generatedAt: string;
    /** `null` quando nenhum chamado atende ao período e aos filtros. */
    report: PerformanceReport | null;
    options: PerformanceOptions;
}>();

/* ---------- Filtros ---------- */

// O painel não tem ordenação: o mesmo valor fixo mantém a URL só com os filtros.
const NO_SORT = { key: 'none' as const, direction: 'asc' as const };

const { form, serverErrors, hasAppliedFilters, formIsEmpty, processing, loadError, retry, submitFilters, clearFilters, goToPage } =
    useListing({
        routeName: 'performance.index',
        filterKeys: performanceFilterKeys,
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

const refresh = () => {
    Object.assign(form, props.filters);
    submitFilters();
};

const periodLabel = computed(() => {
    const range =
        props.period.from === props.period.to
            ? formatDate(props.period.from)
            : `${formatDate(props.period.from)} a ${formatDate(props.period.to)}`;

    return props.period.is_default ? `Últimos 30 dias (${range})` : range;
});

/* ---------- Indicadores ---------- */

const number = (value: number) => value.toLocaleString('pt-BR');
const plural = (count: number, one: string, many: string) => `${number(count)} ${count === 1 ? one : many}`;

const average = (stat: { count: number; average_seconds: number | null }, empty: string) =>
    stat.average_seconds === null
        ? { value: '—', hint: empty }
        : { value: formatDuration(stat.average_seconds), hint: `Média de ${plural(stat.count, 'chamado', 'chamados')}.` };

const firstResponse = computed(() =>
    average(props.report!.totals.first_response, 'Nenhum chamado do período teve a primeira resposta da equipe.'),
);
const resolution = computed(() =>
    average(props.report!.totals.resolution, 'Nenhum chamado resolvido no período tem tempo efetivo registrado.'),
);

const compliance = computed(() => {
    const t = props.report!.totals;

    return t.tracked === 0
        ? { value: '—', hint: 'Nenhum chamado atendido no período tem SLA apurado.' }
        : { value: percent(t.compliance_rate), hint: `${plural(t.within_sla, 'chamado dentro', 'chamados dentro')} do prazo entre ${plural(t.tracked, 'com SLA apurado', 'com SLA apurado')}.` };
});

const violations = computed(() => {
    const t = props.report!.totals;

    return {
        value: number(t.violations),
        hint:
            t.tracked === 0
                ? 'Sem chamados com SLA apurado.'
                : `${plural(t.violated_tickets, 'chamado violado', 'chamados violados')} (${percent(t.violation_rate)} dos apurados): ` +
                  `${number(t.first_response_violations)} de primeira resposta e ${number(t.resolution_violations)} de resolução.`,
    };
});

const statusTotal = computed(() => props.report?.statuses.reduce((sum, item) => sum + item.total, 0) ?? 0);

const technicianAverage = (stat: { average_seconds: number | null }) =>
    stat.average_seconds === null ? '—' : formatDuration(stat.average_seconds);

/** Demais indicadores ao lado do SLA: volume em cima (3 colunas), tempos embaixo (2 colunas). */
const kpis = computed(() => [
    { label: 'Chamados atendidos', value: number(props.report!.totals.attended), hint: 'Abertos no período, com responsável ou não.', span: 'col-span-6 sm:col-span-2' },
    { label: 'Chamados resolvidos', value: number(props.report!.totals.resolved), hint: 'Resolvidos no período (hoje resolvidos ou encerrados).', span: 'col-span-6 sm:col-span-2' },
    { label: 'Violações de SLA', value: violations.value.value, hint: violations.value.hint, span: 'col-span-6 sm:col-span-2' },
    { label: 'Tempo médio de primeira resposta', value: firstResponse.value.value, hint: firstResponse.value.hint, span: 'col-span-6 sm:col-span-3' },
    { label: 'Tempo médio de resolução', value: resolution.value.value, hint: resolution.value.hint, span: 'col-span-6 sm:col-span-3' },
]);
</script>

<template>
    <Head title="Desempenho" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader title="Desempenho dos técnicos" subtitle="Atendimento e cumprimento de SLA dos chamados de todos os departamentos.">
                <p class="text-xs text-neutral-500">Atualizado em {{ formatDateTime(generatedAt) }}</p>
                <Button variant="secondary" :loading="processing" @click="refresh">
                    <Icon v-if="!processing" name="arrow-path" :size="16" /> Atualizar
                </Button>
            </PageHeader>

            <Card>
                <form aria-label="Filtros do painel de desempenho" novalidate @submit.prevent="apply">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <FormField label="Período — de" :error="errorFor('opened_from')" v-slot="{ id, describedby, invalid }">
                            <TextInput :id="id" v-model="form.opened_from" type="date" :max="form.opened_to || undefined" :aria-describedby="describedby" :invalid="invalid" />
                        </FormField>
                        <FormField label="Período — até" :error="errorFor('opened_to')" v-slot="{ id, describedby, invalid }">
                            <TextInput :id="id" v-model="form.opened_to" type="date" :min="form.opened_from || undefined" :aria-describedby="describedby" :invalid="invalid" />
                        </FormField>
                        <FormField label="Técnico" :error="errorFor('technician')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.technician" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todos</option>
                                <option v-for="o in options.technicians" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <FormField label="Departamento" :error="errorFor('department')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.department" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todos</option>
                                <option v-for="o in options.departments" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <FormField label="Categoria" :error="errorFor('category')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.category" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todas</option>
                                <option v-for="o in options.categories" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <FormField label="Status atual" :error="errorFor('status')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.status" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todos</option>
                                <option v-for="o in options.statuses" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <FormField label="Situação do SLA" :error="errorFor('sla')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.sla" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todas</option>
                                <option v-for="o in options.slaStates" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <div class="flex gap-2 sm:col-span-2 lg:col-span-1 lg:mt-6">
                            <Button type="submit" class="flex-1" :disabled="processing || !!dateRangeError">Aplicar</Button>
                            <Button variant="secondary" class="flex-1" :disabled="processing || (!hasAppliedFilters && formIsEmpty)" @click="clearFilters">
                                Limpar
                            </Button>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ periodLabel }}.</span>
                        Sem período escolhido, o painel usa os últimos 30 dias.
                    </p>
                </form>
            </Card>

            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar o painel">
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

                <Card v-if="!report">
                    <EmptyState
                        icon="search"
                        title="Sem dados no período"
                        description="Nenhum chamado dentro do seu escopo foi aberto ou resolvido neste período e com estes filtros. Escolha outro período ou limpe os filtros."
                    >
                        <Button variant="secondary" :disabled="processing" @click="clearFilters">Limpar filtros</Button>
                    </EmptyState>
                </Card>

                <template v-else>
                    <section aria-labelledby="perf-kpis" class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm">
                        <h2 id="perf-kpis" class="sr-only">Indicadores do período</h2>
                        <div class="grid lg:grid-cols-3">
                            <div class="border-b border-neutral-200 p-4 sm:p-6 lg:border-b-0 lg:border-r">
                                <p class="flex items-center gap-2 text-sm font-medium text-neutral-600">
                                    <Icon name="shield-check" :size="16" class="text-neutral-400" /> Cumprimento do SLA
                                </p>
                                <p class="mt-2 text-5xl font-semibold tracking-tight tabular-nums text-neutral-900">{{ compliance.value }}</p>
                                <div v-if="report.totals.tracked" class="mt-4 h-2 rounded-full bg-danger-bg" aria-hidden="true">
                                    <div class="h-2 rounded-full bg-success" :style="{ width: `${report.totals.compliance_rate ?? 0}%` }" />
                                </div>
                                <p class="mt-3 text-sm text-neutral-600">{{ compliance.hint }}</p>
                            </div>

                            <dl class="grid grid-cols-6 gap-px bg-neutral-200 lg:col-span-2">
                                <div v-for="kpi in kpis" :key="kpi.label" class="bg-white p-4 sm:p-6" :class="kpi.span">
                                    <dt class="text-sm text-neutral-600">{{ kpi.label }}</dt>
                                    <dd class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ kpi.value }}</dd>
                                    <dd class="mt-1 text-xs text-neutral-500">{{ kpi.hint }}</dd>
                                </div>
                            </dl>
                        </div>
                    </section>

                    <div class="grid gap-6 lg:grid-cols-3">
                        <EvolutionChart class="lg:col-span-2" :points="report.evolution" :granularity="period.granularity" />

                        <Card title="Por status atual">
                            <BarList :items="report.statuses" :total="statusTotal">
                                <template #label="{ item }"><StatusBadge :status="item.label as TicketStatus" /></template>
                            </BarList>
                        </Card>
                    </div>

                    <section aria-labelledby="perf-techs" class="space-y-3">
                        <h2 id="perf-techs" class="text-lg font-semibold text-neutral-900">Por técnico</h2>
                        <DataTable>
                            <template #head>
                                <tr class="whitespace-nowrap">
                                    <th scope="col">Técnico</th>
                                    <th scope="col" class="!text-right">Atendidos</th>
                                    <th scope="col" class="!text-right">Em atendimento</th>
                                    <th scope="col" class="!text-right">Resolvidos</th>
                                    <th scope="col">Cumprimento do SLA</th>
                                    <th scope="col" class="!text-right">Violações</th>
                                    <th scope="col" class="!text-right">1ª resposta (média)</th>
                                    <th scope="col" class="!text-right">Resolução (média)</th>
                                </tr>
                            </template>

                            <tr v-for="row in report.technicians.data" :key="row.id ?? 'none'">
                                <td class="font-medium" :class="row.id === null && 'text-neutral-600'">{{ row.name }}</td>
                                <td class="text-right tabular-nums">{{ number(row.attended) }}</td>
                                <td class="text-right tabular-nums">{{ number(row.by_status['in_progress'] ?? 0) }}</td>
                                <td class="text-right tabular-nums">{{ number(row.resolved) }}</td>
                                <td class="min-w-44">
                                    <template v-if="row.tracked">
                                        <span class="flex items-center gap-3">
                                            <span class="w-12 font-medium tabular-nums">{{ percent(row.compliance_rate) }}</span>
                                            <span class="h-1.5 flex-1 rounded-full bg-danger-bg" aria-hidden="true">
                                                <span class="block h-1.5 rounded-full bg-success" :style="{ width: `${row.compliance_rate ?? 0}%` }" />
                                            </span>
                                        </span>
                                        <span class="mt-0.5 block text-xs text-neutral-500">{{ number(row.within_sla) }} de {{ number(row.tracked) }} no prazo</span>
                                    </template>
                                    <span v-else class="text-neutral-500">— <span class="text-xs">sem SLA apurado</span></span>
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ number(row.violations) }}
                                    <span v-if="row.violated_tickets" class="block text-xs text-neutral-500">
                                        em {{ plural(row.violated_tickets, 'chamado', 'chamados') }}
                                    </span>
                                </td>
                                <td class="text-right tabular-nums">{{ technicianAverage(row.first_response) }}</td>
                                <td class="text-right tabular-nums">{{ technicianAverage(row.resolution) }}</td>
                            </tr>

                            <tr v-if="!report.technicians.data.length">
                                <td colspan="8" class="text-center text-neutral-600">Nenhum técnico com chamados neste período.</td>
                            </tr>

                            <template #footer>
                                <Pagination
                                    :page="report.technicians.current_page"
                                    :per-page="report.technicians.per_page"
                                    :total="report.technicians.total"
                                    @update:page="goToPage"
                                />
                            </template>
                        </DataTable>
                        <p class="text-xs text-neutral-500">
                            Atendidos: abertos no período, com o técnico como responsável atual. Resolvidos: resolvidos no período, pelo
                            técnico que registrou a resolução mais recente. Cada chamado conta uma vez. "—" indica que não há dado
                            para calcular, não zero. Violações anteriores a uma reabertura continuam contadas.
                        </p>
                    </section>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
