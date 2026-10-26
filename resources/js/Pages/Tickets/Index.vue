<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios, { isAxiosError } from 'axios';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import PriorityBadge from '@/Components/PriorityBadge.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Select from '@/Components/Select.vue';
import SlaIndicator from '@/Components/SlaIndicator.vue';
import SortableHeader from '@/Components/SortableHeader.vue';
import Spinner from '@/Components/Spinner.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import { useToast } from '@/composables/useToast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SelfAssignModal from '@/Pages/Tickets/Partials/SelfAssignModal.vue';
import {
    formatDate,
    formatDateTime,
    ticketFilterKeys,
    type Option,
    type TicketFilterKey,
    type TicketFilterOptions,
    type TicketFilters,
    type TicketListItem,
    type TicketSortKey,
} from '@/lib/ticket';
import type { Paginator } from '@/types';

type Direction = 'asc' | 'desc';

const props = defineProps<{
    tickets: Paginator<TicketListItem>;
    filters: TicketFilters;
    sort: { key: TicketSortKey; direction: Direction };
    options: TicketFilterOptions;
    hasAnyTicket: boolean;
    /** Visão restrita do solicitante (RF-24): sem identificador, solicitante, prioridade e SLA. */
    restricted: boolean;
    can: { create: boolean; export: boolean };
}>();

const page = usePage();

const DEFAULT_SORT: TicketSortKey = 'created_at';
const DEFAULT_DIRECTION: Direction = 'desc';

const sortLabels: Record<TicketSortKey, string> = {
    number: 'ID',
    title: 'Título',
    priority: 'Prioridade',
    status: 'Status',
    assignee: 'Responsável',
    created_at: 'Abertura',
    updated_at: 'Última atualização',
};

/* ---------- Formulário de filtros ---------- */

const toForm = (filters: TicketFilters) =>
    Object.fromEntries(ticketFilterKeys.map((key) => [key, filters[key] ?? ''])) as Record<TicketFilterKey, string>;

const form = reactive(toForm(props.filters));

/** Filtros além da pesquisa rápida, exibidos no painel expansível. */
const advancedKeys = ticketFilterKeys.filter((key) => key !== 'q');
const appliedAdvancedCount = computed(() => advancedKeys.filter((key) => props.filters[key]).length);
const showAdvanced = ref(appliedAdvancedCount.value > 0);

const serverErrors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const dateRangeError = computed(() =>
    form.opened_from && form.opened_to && form.opened_to < form.opened_from
        ? 'A data final deve ser igual ou posterior à data inicial.'
        : undefined,
);

const errorFor = (key: string) => (key === 'opened_to' && dateRangeError.value) || serverErrors.value[key];

const hasAppliedFilters = computed(() => Object.keys(props.filters).length > 0);
const formIsEmpty = computed(() => ticketFilterKeys.every((key) => !form[key]));

// Sincroniza o formulário com os filtros aplicados (inclusive no voltar/avançar do navegador),
// exceto quando a validação falhou, para o usuário corrigir o que digitou.
watch(
    () => props.filters,
    (filters) => {
        if (Object.keys(serverErrors.value).length === 0) Object.assign(form, toForm(filters));
    },
);

/* ---------- Requisições ---------- */

const processing = ref(false);
const loadError = ref(false);
let lastParams: Record<string, string | number> = {};

const compact = (values: Record<string, string | number | undefined>) =>
    Object.fromEntries(Object.entries(values).filter(([, v]) => v !== undefined && v !== '')) as Record<
        string,
        string | number
    >;

/** Ordenação só vai para a URL quando difere do padrão. */
const sortParams = (key: TicketSortKey, direction: Direction) =>
    key === DEFAULT_SORT && direction === DEFAULT_DIRECTION ? {} : { sort: key, direction };

const visit = (params: Record<string, string | number | undefined>) => {
    lastParams = compact(params);
    loadError.value = false;

    // Uma nova visita cancela a anterior no Inertia; os botões também ficam bloqueados enquanto carrega.
    router.get(route('tickets.index'), lastParams, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    });
};

const retry = () => visit(lastParams);

const submitFilters = () => {
    if (dateRangeError.value || processing.value) return;
    // Novo conjunto de filtros volta para a primeira página.
    visit({ ...form, ...sortParams(props.sort.key, props.sort.direction) });
};

const clearFilters = () => {
    Object.assign(form, toForm({}));
    visit({});
};

const removeFilter = (key: TicketFilterKey) => {
    form[key] = '';
    visit({ ...props.filters, [key]: undefined, ...sortParams(props.sort.key, props.sort.direction) });
};

const sortBy = (key: TicketSortKey, direction?: Direction) => {
    const next =
        direction ??
        (props.sort.key === key
            ? props.sort.direction === 'asc'
                ? 'desc'
                : 'asc'
            : key === 'created_at' || key === 'updated_at'
              ? 'desc'
              : 'asc');
    visit({ ...props.filters, ...sortParams(key, next) });
};

const goToPage = (target: number) =>
    visit({ ...props.filters, ...sortParams(props.sort.key, props.sort.direction), page: target });

/* ---------- Exportação CSV (EXP-01) ---------- */

const toast = useToast();
const exporting = ref(false);

/** Mensagem do servidor (JSON dentro do blob) ou uma mensagem genérica por status. */
const exportErrorMessage = async (error: unknown): Promise<string> => {
    if (!isAxiosError(error) || !error.response) return 'Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.';

    const { status, data } = error.response;
    if (status === 403) return 'Você não tem permissão para exportar chamados.';
    if (status === 422) {
        try {
            const body = JSON.parse(await (data as Blob).text()) as { message?: string };
            if (body.message) return body.message;
        } catch {
            /* corpo ilegível: usa a mensagem padrão */
        }
        return 'Os filtros aplicados são inválidos. Revise-os e tente novamente.';
    }

    return 'Não foi possível gerar o arquivo. Tente novamente em instantes.';
};

/**
 * Exporta todos os resultados dos filtros e da ordenação aplicados (não só a página atual). O arquivo
 * é baixado pelo navegador só depois de o servidor devolvê-lo por inteiro; Inertia não é usado, pois a
 * resposta é um arquivo, não uma página.
 */
const exportCsv = async () => {
    if (exporting.value) return;
    exporting.value = true;

    try {
        const response = await axios.get<Blob>(route('tickets.export'), {
            params: { ...props.filters, ...sortParams(props.sort.key, props.sort.direction) },
            responseType: 'blob',
            headers: { Accept: 'application/json, text/csv' },
        });

        if (response.status === 204) {
            toast.info('Nenhum chamado para exportar com os filtros aplicados.');
            return;
        }

        const name = /filename="([^"]+)"/.exec(String(response.headers['content-disposition'] ?? ''))?.[1] ?? 'chamados.csv';
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);

        toast.success('Exportação concluída. O download foi iniciado.');
    } catch (error) {
        toast.error(await exportErrorMessage(error));
    } finally {
        exporting.value = false;
    }
};

const direction = (key: TicketSortKey) => (props.sort.key === key ? props.sort.direction : null);

// Falhas de rede ou respostas inesperadas do servidor viram um estado de erro com nova tentativa.
const removers: (() => void)[] = [];
onMounted(() => {
    removers.push(
        router.on('exception', (event) => {
            event.preventDefault();
            loadError.value = true;
        }),
        router.on('invalid', (event) => {
            if (event.detail.response.status >= 500) {
                event.preventDefault();
                loadError.value = true;
            }
        }),
    );
});
onUnmounted(() => removers.forEach((remove) => remove()));

/* ---------- Filtros ativos ---------- */

const optionLabel = (options: Option[], value: string) => options.find((o) => o.value === value)?.label ?? value;

const chipLabel = (key: TicketFilterKey, value: string): string => {
    const o = props.options;
    switch (key) {
        case 'q':
            return `Pesquisa: “${value}”`;
        case 'number':
            return `ID: ${value}`;
        case 'title':
            return `Título: “${value}”`;
        case 'requester':
            return `Solicitante: ${optionLabel(o.requesters ?? [], value)}`;
        case 'department':
            return `Departamento: ${optionLabel(o.departments, value)}`;
        case 'category':
            return `Categoria: ${optionLabel(o.categories, value)}`;
        case 'priority':
            return `Prioridade: ${optionLabel(o.priorities ?? [], value)}`;
        case 'status':
            return `Status: ${optionLabel(o.statuses, value)}`;
        case 'assignee':
            return `Responsável: ${value === 'none' ? 'Não atribuído' : optionLabel(o.assignees, value)}`;
        case 'opened_from':
            return `Aberto a partir de ${formatDate(value)}`;
        case 'opened_to':
            return `Aberto até ${formatDate(value)}`;
        case 'sla':
            return `SLA: ${optionLabel(o.slaStates ?? [], value)}`;
    }
};

const activeChips = computed(() =>
    ticketFilterKeys
        .filter((key) => props.filters[key])
        .map((key) => ({ key, label: chipLabel(key, props.filters[key]!) })),
);

/* ---------- Resultados ---------- */

const rows = computed(() => props.tickets.data);

/* ---------- Autoatribuição (CHA-06) ---------- */

const selfAssignNumber = ref<string | null>(null);
/** A coluna de ação só aparece quando há algum chamado que o usuário pode assumir. */
const showSelfAssign = computed(() => rows.value.some((ticket) => ticket.can_self_assign));
const actionColumnClass = 'sticky right-0 border-l border-neutral-200';

/** Título como link para os detalhes (CHA-03); azul de texto usa primary-600 (design-system.md, 2.1). */
const titleLinkClass =
    'rounded text-neutral-900 hover:text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
const isEmpty = computed(() => rows.value.length === 0);

const mobileSort = computed({
    get: () => props.sort.key,
    set: (key: TicketSortKey) => sortBy(key, props.sort.key === key ? props.sort.direction : undefined),
});
</script>

<template>
    <Head title="Chamados" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader title="Chamados" subtitle="Pesquise e acompanhe os chamados conforme seu acesso.">
                <Button
                    v-if="can.export && hasAnyTicket"
                    variant="secondary"
                    :loading="exporting"
                    :disabled="processing"
                    :aria-label="hasAppliedFilters ? 'Exportar CSV dos chamados filtrados' : 'Exportar CSV de todos os chamados'"
                    @click="exportCsv"
                >
                    <Icon name="download" :size="16" /> {{ exporting ? 'Exportando…' : 'Exportar CSV' }}
                </Button>
                <Button v-if="can.create" :href="route('tickets.create')">
                    <Icon name="plus" :size="16" /> Novo chamado
                </Button>
            </PageHeader>

            <!-- Pesquisa e filtros -->
            <Card>
                <form role="search" aria-label="Pesquisa e filtros de chamados" @submit.prevent="submitFilters">
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <div class="min-w-0 flex-1">
                            <label for="ticket-search" class="sr-only">Pesquisar chamados</label>
                            <SearchInput
                                id="ticket-search"
                                v-model="form.q"
                                placeholder="Buscar por ID ou título..."
                                maxlength="100"
                            />
                        </div>
                        <div class="flex gap-2">
                            <Button
                                variant="secondary"
                                class="flex-1 sm:flex-none"
                                aria-controls="ticket-advanced-filters"
                                :aria-expanded="showAdvanced"
                                @click="showAdvanced = !showAdvanced"
                            >
                                <Icon name="funnel" :size="16" />
                                Filtros
                                <span
                                    v-if="appliedAdvancedCount"
                                    class="rounded-full bg-primary-100 px-2 text-xs font-semibold text-primary-800"
                                >
                                    {{ appliedAdvancedCount }}
                                    <span class="sr-only">filtros ativos</span>
                                </span>
                            </Button>
                            <Button type="submit" class="flex-1 sm:flex-none" :disabled="processing || !!dateRangeError">
                                Filtrar
                            </Button>
                        </div>
                    </div>

                    <div
                        v-show="showAdvanced"
                        id="ticket-advanced-filters"
                        class="mt-4 grid gap-4 border-t border-neutral-200 pt-4 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <FormField v-if="!restricted" label="Identificador" :error="errorFor('number')" v-slot="{ id, describedby, invalid }">
                            <TextInput
                                :id="id"
                                v-model="form.number"
                                class="font-mono"
                                placeholder="HD-…"
                                maxlength="11"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                            />
                        </FormField>

                        <FormField label="Título" :error="errorFor('title')" v-slot="{ id, describedby, invalid }">
                            <TextInput :id="id" v-model="form.title" maxlength="255" :aria-describedby="describedby" :invalid="invalid" />
                        </FormField>

                        <FormField v-if="!restricted" label="Solicitante" :error="errorFor('requester')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.requester" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todos</option>
                                <option v-for="o in options.requesters ?? []" :key="o.value" :value="o.value">{{ o.label }}</option>
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

                        <FormField v-if="!restricted" label="Prioridade" :error="errorFor('priority')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.priority" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todas</option>
                                <option v-for="o in options.priorities ?? []" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>

                        <FormField label="Status" :error="errorFor('status')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.status" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todos</option>
                                <option v-for="o in options.statuses" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>

                        <FormField label="Responsável" :error="errorFor('assignee')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.assignee" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todos</option>
                                <option value="none">Não atribuído</option>
                                <option v-for="o in options.assignees" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>

                        <FormField label="Abertura — de" :error="errorFor('opened_from')" v-slot="{ id, describedby, invalid }">
                            <TextInput
                                :id="id"
                                v-model="form.opened_from"
                                type="date"
                                :max="form.opened_to || undefined"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                            />
                        </FormField>

                        <FormField label="Abertura — até" :error="errorFor('opened_to')" v-slot="{ id, describedby, invalid }">
                            <TextInput
                                :id="id"
                                v-model="form.opened_to"
                                type="date"
                                :min="form.opened_from || undefined"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                            />
                        </FormField>

                        <FormField v-if="!restricted" label="Situação do SLA" :error="errorFor('sla')" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.sla" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todas</option>
                                <option v-for="o in options.slaStates ?? []" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <!-- Filtros ativos: cada um pode ser removido individualmente -->
                        <div v-if="activeChips.length" class="min-w-0">
                            <p id="active-filters-label" class="sr-only">Filtros ativos</p>
                            <ul class="flex flex-wrap gap-2" aria-labelledby="active-filters-label">
                                <li v-for="chip in activeChips" :key="chip.key">
                                    <button
                                        type="button"
                                        class="inline-flex h-10 max-w-full items-center gap-1 rounded-full bg-primary-50 px-3 text-xs font-medium text-primary-800 transition-colors duration-150 hover:bg-primary-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 sm:h-8"
                                        :aria-label="`Remover filtro ${chip.label}`"
                                        :disabled="processing"
                                        @click="removeFilter(chip.key)"
                                    >
                                        <span class="truncate">{{ chip.label }}</span>
                                        <Icon name="x" :size="16" />
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <p v-else class="text-sm text-neutral-500">Nenhum filtro aplicado.</p>

                        <Button
                            variant="ghost"
                            class="shrink-0 self-start"
                            :disabled="processing || (!hasAppliedFilters && formIsEmpty)"
                            @click="clearFilters"
                        >
                            Limpar filtros
                        </Button>
                    </div>
                </form>
            </Card>

            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar os chamados">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="retry">Tentar novamente</Button>
            </Alert>

            <!-- Resultados -->
            <section aria-labelledby="ticket-results-title" :aria-busy="processing">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="ticket-results-title" class="text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ tickets.total }}</span>
                        {{ tickets.total === 1 ? 'chamado encontrado' : 'chamados encontrados' }}
                    </h2>
                    <p
                        v-if="processing"
                        class="inline-flex items-center gap-2 text-sm text-neutral-600"
                        role="status"
                        aria-live="polite"
                    >
                        <Spinner /> Atualizando resultados…
                    </p>

                    <!-- Ordenação no celular, onde a tabela vira cartões -->
                    <div v-if="!isEmpty" class="flex w-full items-end gap-2 md:hidden">
                        <div class="min-w-0 flex-1">
                            <label for="ticket-sort" class="text-xs font-medium text-neutral-700">Ordenar por</label>
                            <Select id="ticket-sort" v-model="mobileSort" class="mt-1" :disabled="processing">
                                <option v-for="(label, key) in sortLabels" :key="key" :value="key">{{ label }}</option>
                            </Select>
                        </div>
                        <Button
                            variant="secondary"
                            class="w-10 px-0"
                            :disabled="processing"
                            :aria-label="sort.direction === 'asc' ? 'Ordem crescente. Alternar para decrescente' : 'Ordem decrescente. Alternar para crescente'"
                            @click="sortBy(sort.key)"
                        >
                            <Icon :name="sort.direction === 'asc' ? 'arrow-up' : 'arrow-down'" :size="16" />
                        </Button>
                    </div>
                </div>

                <div class="transition-opacity duration-150" :class="processing && 'opacity-60'">
                    <!-- Estados vazios -->
                    <Card v-if="isEmpty" flush>
                        <EmptyState
                            v-if="hasAnyTicket || hasAppliedFilters"
                            icon="search"
                            title="Nenhum chamado corresponde aos critérios"
                            description="Revise a pesquisa ou os filtros aplicados."
                        >
                            <Button variant="secondary" :disabled="processing" @click="clearFilters">Limpar filtros</Button>
                        </EmptyState>
                        <EmptyState
                            v-else
                            title="Nenhum chamado por aqui"
                            description="Ainda não há chamados disponíveis para o seu acesso."
                        >
                            <Button v-if="can.create" :href="route('tickets.create')">
                                <Icon name="plus" :size="16" /> Abrir o primeiro chamado
                            </Button>
                        </EmptyState>
                    </Card>

                    <template v-else>
                        <!-- Tablet e desktop: tabela (rola na horizontal dentro do próprio container) -->
                        <div class="hidden md:block">
                            <DataTable>
                                <template #head>
                                    <tr class="whitespace-nowrap">
                                        <SortableHeader v-if="!restricted" label="ID" :direction="direction('number')" @sort="sortBy('number')" />
                                        <SortableHeader label="Título" :direction="direction('title')" @sort="sortBy('title')" />
                                        <th v-if="!restricted" scope="col">Solicitante</th>
                                        <th scope="col">Departamento</th>
                                        <th scope="col">Categoria</th>
                                        <SortableHeader v-if="!restricted" label="Prioridade" :direction="direction('priority')" @sort="sortBy('priority')" />
                                        <SortableHeader label="Status" :direction="direction('status')" @sort="sortBy('status')" />
                                        <SortableHeader label="Responsável" :direction="direction('assignee')" @sort="sortBy('assignee')" />
                                        <SortableHeader label="Abertura" :direction="direction('created_at')" @sort="sortBy('created_at')" />
                                        <SortableHeader label="Atualização" :direction="direction('updated_at')" @sort="sortBy('updated_at')" />
                                        <th v-if="!restricted" scope="col">SLA</th>
                                        <!-- Fixa à direita para o botão ficar visível sem rolar a tabela. Por ser
                                             posicionada, também mantém o texto sr-only dentro da área com rolagem. -->
                                        <th v-if="showSelfAssign" scope="col" :class="actionColumnClass" class="bg-neutral-100">
                                            <span class="sr-only">Ações</span>
                                        </th>
                                    </tr>
                                </template>

                                <tr v-for="ticket in rows" :key="ticket.number">
                                    <td v-if="!restricted" class="whitespace-nowrap"><TicketNumber :number="ticket.number" /></td>
                                    <td class="min-w-[14rem] font-medium">
                                        <Link :href="route('tickets.show', ticket.number)" :class="titleLinkClass">
                                            {{ ticket.title }}
                                        </Link>
                                    </td>
                                    <td v-if="!restricted" class="whitespace-nowrap text-neutral-600">{{ ticket.requester ?? '—' }}</td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ ticket.department ?? '—' }}</td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ ticket.category ?? '—' }}</td>
                                    <td v-if="!restricted"><PriorityBadge v-if="ticket.priority" :priority="ticket.priority" /></td>
                                    <td><StatusBadge :status="ticket.status" /></td>
                                    <td class="whitespace-nowrap">
                                        <span v-if="ticket.assignee" class="text-neutral-600">{{ ticket.assignee }}</span>
                                        <span v-else class="text-neutral-500">Não atribuído</span>
                                    </td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ formatDateTime(ticket.created_at) }}</td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ formatDateTime(ticket.updated_at) }}</td>
                                    <td v-if="!restricted" class="whitespace-nowrap">
                                        <SlaIndicator v-if="ticket.sla && ticket.sla !== 'none'" :state="ticket.sla" />
                                        <span v-else class="text-xs text-neutral-500">Sem prazo definido</span>
                                    </td>
                                    <td
                                        v-if="showSelfAssign"
                                        :class="actionColumnClass"
                                        class="whitespace-nowrap bg-white text-right [tr:hover_&]:bg-neutral-100"
                                    >
                                        <Button
                                            v-if="ticket.can_self_assign"
                                            size="sm"
                                            variant="secondary"
                                            :aria-label="`Assumir chamado ${ticket.number}`"
                                            @click="selfAssignNumber = ticket.number"
                                        >
                                            Assumir
                                        </Button>
                                    </td>
                                </tr>

                                <template #footer>
                                    <Pagination
                                        :page="tickets.current_page"
                                        :per-page="tickets.per_page"
                                        :total="tickets.total"
                                        @update:page="goToPage"
                                    />
                                </template>
                            </DataTable>
                        </div>

                        <!-- Celular: cartões compactos -->
                        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm md:hidden">
                            <ul class="divide-y divide-neutral-200">
                                <li v-for="ticket in rows" :key="ticket.number" class="space-y-2 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <TicketNumber v-if="!restricted" :number="ticket.number" />
                                        <StatusBadge :status="ticket.status" />
                                    </div>
                                    <p class="break-words text-sm font-medium text-neutral-900">
                                        <Link :href="route('tickets.show', ticket.number)" :class="titleLinkClass">
                                            {{ ticket.title }}
                                        </Link>
                                    </p>
                                    <div v-if="!restricted" class="flex flex-wrap items-center gap-2">
                                        <PriorityBadge v-if="ticket.priority" :priority="ticket.priority" />
                                        <SlaIndicator v-if="ticket.sla && ticket.sla !== 'none'" :state="ticket.sla" />
                                        <span v-else class="text-xs text-neutral-500">Sem prazo definido</span>
                                    </div>
                                    <p class="text-xs text-neutral-500">
                                        {{ ticket.assignee ?? 'Não atribuído' }} · Aberto em {{ formatDateTime(ticket.created_at) }}
                                    </p>
                                    <Button
                                        v-if="ticket.can_self_assign"
                                        variant="secondary"
                                        block
                                        :aria-label="`Assumir chamado ${ticket.number}`"
                                        @click="selfAssignNumber = ticket.number"
                                    >
                                        Assumir chamado
                                    </Button>
                                </li>
                            </ul>
                            <Pagination
                                :page="tickets.current_page"
                                :per-page="tickets.per_page"
                                :total="tickets.total"
                                @update:page="goToPage"
                            />
                        </div>
                    </template>
                </div>
            </section>
        </div>

        <SelfAssignModal :number="selfAssignNumber" @close="selfAssignNumber = null" />
    </AuthenticatedLayout>
</template>
