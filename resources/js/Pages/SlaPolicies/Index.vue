<script setup lang="ts">
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import PriorityBadge from '@/Components/PriorityBadge.vue';
import Select from '@/Components/Select.vue';
import SortableHeader from '@/Components/SortableHeader.vue';
import Spinner from '@/Components/Spinner.vue';
import StatCard from '@/Components/StatCard.vue';
import { useListing, type Direction } from '@/composables/useListing';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    formatTarget,
    slaPolicyFilterKeys,
    type SlaPolicyFilterKey,
    type SlaPolicyFilterOptions,
    type SlaPolicyFilters,
    type SlaPolicyListItem,
    type SlaPolicySortKey,
    type SlaPolicySummary,
} from '@/lib/sla';
import { formatShortDate } from '@/lib/user';
import type { Paginator } from '@/types';

const props = defineProps<{
    policies: Paginator<SlaPolicyListItem>;
    summary: SlaPolicySummary;
    filters: SlaPolicyFilters;
    sort: { key: SlaPolicySortKey; direction: Direction };
    options: SlaPolicyFilterOptions;
    can: { create: boolean };
}>();

const page = usePage();

const sortLabels: Record<SlaPolicySortKey, string> = {
    priority: 'Prioridade',
    first_response_minutes: 'Meta de primeira resposta',
    resolution_minutes: 'Meta de resolução',
    updated_at: 'Última atualização',
};

const {
    form,
    serverErrors,
    hasAppliedFilters,
    formIsEmpty,
    processing,
    loadError,
    retry,
    submitFilters,
    clearFilters,
    removeFilter,
    sortBy,
    goToPage,
    direction,
    mobileSort,
} = useListing({
    routeName: 'sla-policies.index',
    filterKeys: slaPolicyFilterKeys,
    filters: () => props.filters,
    sort: () => props.sort,
    defaultSort: { key: 'priority', direction: 'asc' },
    descendingFirst: ['updated_at'],
});

/** Não há pesquisa livre (a política não tem campos de texto): cada filtro é aplicado ao ser escolhido. */
const applyFilter = (key: SlaPolicyFilterKey, value: string | number | null) => {
    form[key] = String(value ?? '');
    submitFilters();
};

/* ---------- Filtros ativos ---------- */

const chipLabel = (key: SlaPolicyFilterKey, value: string): string => {
    switch (key) {
        case 'priority':
            return `Prioridade: ${props.options.priorities.find((o) => o.value === value)?.label ?? value}`;
        case 'status':
            return `Situação: ${props.options.statuses.find((o) => o.value === value)?.label ?? value}`;
    }
};

const activeChips = computed(() =>
    slaPolicyFilterKeys
        .filter((key) => props.filters[key])
        .map((key) => ({ key, label: chipLabel(key, props.filters[key]!) })),
);

/* ---------- Resultados ---------- */

const rows = computed(() => props.policies.data);
const isEmpty = computed(() => rows.value.length === 0);
/** Base vazia, e não apenas um filtro sem resultados. */
const hasNoPolicies = computed(() => props.summary.total === 0);
/** A coluna de ação só aparece quando a tela de detalhes (SLA-04) estiver disponível. */
const showDetails = computed(() => rows.value.some((policy) => policy.can_view || policy.can_edit));
const editLabel = (policy: SlaPolicyListItem) => `Editar a política de prioridade ${policy.priority}`;
const successMessage = computed(() => page.props.flash?.success ?? null);
const formatCount = (value: number) => value.toLocaleString('pt-BR');
const situation = (policy: SlaPolicyListItem) => (policy.is_active ? 'Ativa' : 'Inativa');
const detailsLabel = (policy: SlaPolicyListItem) => `Ver detalhes da política de prioridade ${policy.priority}`;

const breadcrumbs = [{ label: 'Início', href: route('dashboard') }, { label: 'SLA e pendências' }, { label: 'Políticas de SLA' }];
</script>

<template>
    <Head title="Políticas de SLA" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Políticas de SLA" subtitle="Consulte as políticas e suas configurações de nível de serviço.">
                    <Button v-if="can.create" :href="route('sla-policies.create')">
                        <Icon name="plus" :size="16" /> Nova política
                    </Button>
                </PageHeader>
            </div>

            <!-- Indicadores: todas as políticas, independentemente dos filtros -->
            <section aria-label="Resumo das políticas de SLA" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Total de políticas" :value="formatCount(summary.total)" hint="Ativas e inativas" />
                <StatCard label="Ativas" :value="formatCount(summary.active)" hint="Metas em vigor" />
                <StatCard label="Inativas" :value="formatCount(summary.inactive)" hint="Mantidas para o histórico" />
                <StatCard
                    label="Prioridades com política ativa"
                    :value="`${summary.covered} de ${summary.priorities}`"
                    hint="Cada prioridade precisa de uma política ativa"
                />
            </section>

            <!-- Filtros -->
            <Card>
                <form aria-label="Filtros de políticas de SLA" @submit.prevent="submitFilters">
                    <div class="grid gap-3 sm:grid-cols-2 lg:max-w-xl">
                        <FormField label="Prioridade" :error="serverErrors.priority" v-slot="{ id, describedby, invalid }">
                            <Select
                                :id="id"
                                :model-value="form.priority"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                :disabled="processing"
                                @update:model-value="applyFilter('priority', $event)"
                            >
                                <option value="">Todas</option>
                                <option v-for="o in options.priorities" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <FormField label="Situação" :error="serverErrors.status" v-slot="{ id, describedby, invalid }">
                            <Select
                                :id="id"
                                :model-value="form.status"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                :disabled="processing"
                                @update:model-value="applyFilter('status', $event)"
                            >
                                <option value="">Todas</option>
                                <option v-for="o in options.statuses" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <!-- Filtros ativos: cada um pode ser removido individualmente -->
                        <div v-if="activeChips.length" class="min-w-0">
                            <p id="active-sla-policy-filters-label" class="sr-only">Filtros ativos</p>
                            <ul class="flex flex-wrap gap-2" aria-labelledby="active-sla-policy-filters-label">
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

            <!-- Confirmação vinda de outra tela, só depois da gravação real -->
            <Alert v-if="successMessage" variant="success">{{ successMessage }}</Alert>

            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar as políticas de SLA">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="retry">Tentar novamente</Button>
            </Alert>

            <!-- Resultados -->
            <section aria-labelledby="sla-policy-results-title" :aria-busy="processing">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="sla-policy-results-title" class="text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ formatCount(policies.total) }}</span>
                        {{ policies.total === 1 ? 'política encontrada' : 'políticas encontradas' }}
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
                            <label for="sla-policy-sort" class="text-xs font-medium text-neutral-700">Ordenar por</label>
                            <Select id="sla-policy-sort" v-model="mobileSort" class="mt-1" :disabled="processing">
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
                            v-if="hasNoPolicies"
                            icon="clock"
                            title="Nenhuma política de SLA cadastrada"
                            description="Ainda não há metas de primeira resposta e de resolução configuradas para as prioridades."
                        >
                            <Button v-if="can.create" :href="route('sla-policies.create')">
                                <Icon name="plus" :size="16" /> Cadastrar política
                            </Button>
                        </EmptyState>
                        <EmptyState
                            v-else-if="hasAppliedFilters"
                            icon="funnel"
                            title="Nenhuma política encontrada"
                            description="Nenhuma política corresponde aos filtros aplicados."
                        >
                            <Button variant="secondary" :disabled="processing" @click="clearFilters">Limpar filtros</Button>
                        </EmptyState>
                        <!-- Página além da última, por exemplo depois de um link antigo -->
                        <EmptyState v-else icon="search" title="Nenhuma política encontrada" description="Esta página não tem resultados.">
                            <Button variant="secondary" :disabled="processing" @click="goToPage(1)">Ir para a primeira página</Button>
                        </EmptyState>
                    </Card>

                    <template v-else>
                        <!-- Tablet e desktop: tabela -->
                        <div class="hidden md:block">
                            <DataTable>
                                <template #head>
                                    <tr class="whitespace-nowrap">
                                        <SortableHeader label="Prioridade" :direction="direction('priority')" @sort="sortBy('priority')" />
                                        <SortableHeader
                                            label="1ª resposta"
                                            :direction="direction('first_response_minutes')"
                                            @sort="sortBy('first_response_minutes')"
                                        />
                                        <SortableHeader
                                            label="Resolução"
                                            :direction="direction('resolution_minutes')"
                                            @sort="sortBy('resolution_minutes')"
                                        />
                                        <th scope="col">Situação</th>
                                        <SortableHeader label="Atualização" :direction="direction('updated_at')" @sort="sortBy('updated_at')" />
                                        <th v-if="showDetails" scope="col"><span class="sr-only">Ações</span></th>
                                    </tr>
                                </template>

                                <tr v-for="policy in rows" :key="policy.id" :class="!policy.is_active && 'bg-neutral-50'">
                                    <td><PriorityBadge :priority="policy.priority" /></td>
                                    <td class="whitespace-nowrap" :class="policy.is_active ? 'text-neutral-900' : 'text-neutral-600'">
                                        {{ formatTarget(policy.first_response_minutes) }}
                                    </td>
                                    <td class="whitespace-nowrap" :class="policy.is_active ? 'text-neutral-900' : 'text-neutral-600'">
                                        {{ formatTarget(policy.resolution_minutes) }}
                                    </td>
                                    <td>
                                        <Badge :tone="policy.is_active ? 'success' : 'neutral'">{{ situation(policy) }}</Badge>
                                    </td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ formatShortDate(policy.updated_at) }}</td>
                                    <td v-if="showDetails" class="whitespace-nowrap text-right">
                                        <Button
                                            v-if="policy.can_view"
                                            variant="ghost"
                                            size="sm"
                                            :href="route('sla-policies.show', policy.id)"
                                            :aria-label="detailsLabel(policy)"
                                        >
                                            <Icon name="eye" :size="16" /> Detalhes
                                        </Button>
                                        <Button
                                            v-if="policy.can_edit"
                                            variant="ghost"
                                            size="sm"
                                            :href="route('sla-policies.edit', policy.id)"
                                            :aria-label="editLabel(policy)"
                                        >
                                            <Icon name="pencil" :size="16" /> Editar
                                        </Button>
                                    </td>
                                </tr>

                                <template #footer>
                                    <Pagination
                                        :page="policies.current_page"
                                        :per-page="policies.per_page"
                                        :total="policies.total"
                                        @update:page="goToPage"
                                    />
                                </template>
                            </DataTable>
                        </div>

                        <!-- Celular: cartões compactos -->
                        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm md:hidden">
                            <ul class="divide-y divide-neutral-200">
                                <li v-for="policy in rows" :key="policy.id" class="space-y-2 p-4" :class="!policy.is_active && 'bg-neutral-50'">
                                    <div class="flex items-start justify-between gap-2">
                                        <PriorityBadge :priority="policy.priority" />
                                        <Badge :tone="policy.is_active ? 'success' : 'neutral'" class="shrink-0">{{ situation(policy) }}</Badge>
                                    </div>
                                    <dl class="grid grid-cols-2 gap-2 text-sm">
                                        <div>
                                            <dt class="text-xs text-neutral-500">1ª resposta</dt>
                                            <dd class="font-medium text-neutral-900">{{ formatTarget(policy.first_response_minutes) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-neutral-500">Resolução</dt>
                                            <dd class="font-medium text-neutral-900">{{ formatTarget(policy.resolution_minutes) }}</dd>
                                        </div>
                                    </dl>
                                    <p class="text-xs text-neutral-500">Atualizada em {{ formatShortDate(policy.updated_at) }}</p>
                                    <Button
                                        v-if="policy.can_view"
                                        variant="secondary"
                                        block
                                        class="!mt-3"
                                        :href="route('sla-policies.show', policy.id)"
                                        :aria-label="detailsLabel(policy)"
                                    >
                                        <Icon name="eye" :size="16" /> Detalhes
                                    </Button>
                                    <Button
                                        v-if="policy.can_edit"
                                        variant="secondary"
                                        block
                                        class="!mt-3"
                                        :href="route('sla-policies.edit', policy.id)"
                                        :aria-label="editLabel(policy)"
                                    >
                                        <Icon name="pencil" :size="16" /> Editar
                                    </Button>
                                </li>
                            </ul>
                            <Pagination
                                :page="policies.current_page"
                                :per-page="policies.per_page"
                                :total="policies.total"
                                @update:page="goToPage"
                            />
                        </div>
                    </template>
                </div>

                <p v-if="!isEmpty" class="mt-3 text-xs text-neutral-500">
                    Metas contadas em tempo corrido, sem calendário de horário comercial.
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
