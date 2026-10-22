<script setup lang="ts">
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AdminSectionNav from '@/Components/AdminSectionNav.vue';
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
import SearchInput from '@/Components/SearchInput.vue';
import Select from '@/Components/Select.vue';
import SortableHeader from '@/Components/SortableHeader.vue';
import Spinner from '@/Components/Spinner.vue';
import StatCard from '@/Components/StatCard.vue';
import { useListing, type Direction } from '@/composables/useListing';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    categoryFilterKeys,
    type CategoryFilterKey,
    type CategoryFilterOptions,
    type CategoryFilters,
    type CategoryListItem,
    type CategorySortKey,
    type CategorySummary,
} from '@/lib/category';
import { adminHomeHref } from '@/lib/navigation';
import { formatShortDate } from '@/lib/user';
import type { Paginator } from '@/types';

const props = defineProps<{
    categories: Paginator<CategoryListItem>;
    summary: CategorySummary;
    filters: CategoryFilters;
    sort: { key: CategorySortKey; direction: Direction };
    options: CategoryFilterOptions;
    can: { create: boolean };
}>();

const page = usePage();

const sortLabels: Record<CategorySortKey, string> = {
    name: 'Nome',
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
    routeName: 'admin.categories.index',
    filterKeys: categoryFilterKeys,
    filters: () => props.filters,
    sort: () => props.sort,
    defaultSort: { key: 'name', direction: 'asc' },
    descendingFirst: ['updated_at'],
});

/** A situação é aplicada assim que o usuário a escolhe, mantendo a pesquisa digitada. */
const applyStatus = (status: string | number | null) => {
    form.status = String(status ?? '');
    submitFilters();
};

/* ---------- Filtros ativos ---------- */

const chipLabel = (key: CategoryFilterKey, value: string): string => {
    switch (key) {
        case 'q':
            return `Pesquisa: “${value}”`;
        case 'status':
            return `Situação: ${props.options.statuses.find((o) => o.value === value)?.label ?? value}`;
    }
};

const activeChips = computed(() =>
    categoryFilterKeys
        .filter((key) => props.filters[key])
        .map((key) => ({ key, label: chipLabel(key, props.filters[key]!) })),
);

/* ---------- Resultados ---------- */

const rows = computed(() => props.categories.data);
const isEmpty = computed(() => rows.value.length === 0);
/** Base vazia, e não apenas uma pesquisa sem resultados. */
const hasNoCategories = computed(() => props.summary.total === 0);
/** A coluna de ação só aparece quando há alguma categoria que o operador pode editar (ADM-09). */
const showEdit = computed(() => rows.value.some((category) => category.can_edit));
const successMessage = computed(() => page.props.flash?.success ?? null);
const formatCount = (value: number) => value.toLocaleString('pt-BR');

const breadcrumbs = computed(() => [
    { label: 'Administração', href: adminHomeHref(page.props.auth.permissions) },
    { label: 'Categorias' },
]);
</script>

<template>
    <Head title="Categorias" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Categorias" subtitle="Consulte e gerencie as categorias utilizadas na classificação dos chamados.">
                    <Button v-if="can.create" :href="route('admin.categories.create')">
                        <Icon name="plus" :size="16" /> Nova categoria
                    </Button>
                </PageHeader>
            </div>

            <AdminSectionNav />

            <!-- Indicadores: todas as categorias, independentemente da pesquisa -->
            <section aria-label="Resumo das categorias" class="grid gap-4 sm:grid-cols-3">
                <StatCard label="Total de categorias" :value="formatCount(summary.total)" hint="Ativas e inativas" />
                <StatCard label="Ativas" :value="formatCount(summary.active)" hint="Disponíveis para novos chamados" />
                <StatCard label="Inativas" :value="formatCount(summary.inactive)" hint="Mantidas nos chamados antigos" />
            </section>

            <!-- Pesquisa e filtros -->
            <Card>
                <form role="search" aria-label="Pesquisa e filtros de categorias" @submit.prevent="submitFilters">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <label for="category-search" class="text-sm font-medium text-neutral-700">Pesquisar</label>
                            <div class="mt-1">
                                <SearchInput
                                    id="category-search"
                                    v-model="form.q"
                                    placeholder="Nome ou descrição..."
                                    maxlength="100"
                                    :aria-invalid="serverErrors.q ? 'true' : undefined"
                                    :aria-describedby="serverErrors.q ? 'category-search-error' : undefined"
                                />
                            </div>
                            <p v-if="serverErrors.q" id="category-search-error" class="mt-1 text-sm text-danger-text">
                                {{ serverErrors.q }}
                            </p>
                        </div>
                        <div class="sm:w-48">
                            <FormField label="Situação" :error="serverErrors.status" v-slot="{ id, describedby, invalid }">
                                <Select
                                    :id="id"
                                    :model-value="form.status"
                                    :aria-describedby="describedby"
                                    :invalid="invalid"
                                    :disabled="processing"
                                    @update:model-value="applyStatus"
                                >
                                    <option value="">Todas</option>
                                    <option v-for="o in options.statuses" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </Select>
                            </FormField>
                        </div>
                        <Button type="submit" :disabled="processing">Filtrar</Button>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <!-- Filtros ativos: cada um pode ser removido individualmente -->
                        <div v-if="activeChips.length" class="min-w-0">
                            <p id="active-category-filters-label" class="sr-only">Filtros ativos</p>
                            <ul class="flex flex-wrap gap-2" aria-labelledby="active-category-filters-label">
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

            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar as categorias">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="retry">Tentar novamente</Button>
            </Alert>

            <!-- Resultados -->
            <section aria-labelledby="category-results-title" :aria-busy="processing">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="category-results-title" class="text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ formatCount(categories.total) }}</span>
                        {{ categories.total === 1 ? 'categoria encontrada' : 'categorias encontradas' }}
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
                            <label for="category-sort" class="text-xs font-medium text-neutral-700">Ordenar por</label>
                            <Select id="category-sort" v-model="mobileSort" class="mt-1" :disabled="processing">
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
                            v-if="hasNoCategories"
                            icon="squares"
                            title="Nenhuma categoria cadastrada"
                            description="Ainda não há categorias para classificar os chamados."
                        >
                            <Button v-if="can.create" :href="route('admin.categories.create')">
                                <Icon name="plus" :size="16" /> Cadastrar categoria
                            </Button>
                        </EmptyState>
                        <EmptyState
                            v-else-if="hasAppliedFilters"
                            icon="search"
                            title="Nenhuma categoria encontrada"
                            description="Nenhuma categoria corresponde à pesquisa ou aos filtros aplicados."
                        >
                            <Button variant="secondary" :disabled="processing" @click="clearFilters">Limpar filtros</Button>
                        </EmptyState>
                        <!-- Página além da última, por exemplo depois de um link antigo -->
                        <EmptyState
                            v-else
                            icon="search"
                            title="Nenhuma categoria encontrada"
                            description="Esta página não tem resultados."
                        >
                            <Button variant="secondary" :disabled="processing" @click="goToPage(1)">Ir para a primeira página</Button>
                        </EmptyState>
                    </Card>

                    <template v-else>
                        <!-- Tablet e desktop: tabela -->
                        <div class="hidden md:block">
                            <DataTable>
                                <template #head>
                                    <tr class="whitespace-nowrap">
                                        <SortableHeader label="Categoria" :direction="direction('name')" @sort="sortBy('name')" />
                                        <th scope="col">Descrição</th>
                                        <th scope="col">Situação</th>
                                        <SortableHeader label="Atualização" :direction="direction('updated_at')" @sort="sortBy('updated_at')" />
                                        <th v-if="showEdit" scope="col"><span class="sr-only">Ações</span></th>
                                    </tr>
                                </template>

                                <tr v-for="category in rows" :key="category.id" :class="!category.is_active && 'bg-neutral-50'">
                                    <td class="min-w-[10rem] font-medium" :class="!category.is_active && 'text-neutral-600'">
                                        {{ category.name }}
                                    </td>
                                    <td class="min-w-[16rem] max-w-md text-neutral-600">
                                        <p v-if="category.description" class="line-clamp-2 break-words" :title="category.description">
                                            {{ category.description }}
                                        </p>
                                        <span v-else class="text-neutral-500">Sem descrição</span>
                                    </td>
                                    <td>
                                        <Badge :tone="category.is_active ? 'success' : 'neutral'">
                                            {{ category.is_active ? 'Ativa' : 'Inativa' }}
                                        </Badge>
                                    </td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ formatShortDate(category.updated_at) }}</td>
                                    <td v-if="showEdit" class="whitespace-nowrap text-right">
                                        <Button
                                            v-if="category.can_edit"
                                            variant="ghost"
                                            size="sm"
                                            :href="route('admin.categories.edit', category.id)"
                                            :aria-label="`Editar ${category.name}`"
                                        >
                                            <Icon name="pencil" :size="16" /> Editar
                                        </Button>
                                    </td>
                                </tr>

                                <template #footer>
                                    <Pagination
                                        :page="categories.current_page"
                                        :per-page="categories.per_page"
                                        :total="categories.total"
                                        @update:page="goToPage"
                                    />
                                </template>
                            </DataTable>
                        </div>

                        <!-- Celular: cartões compactos -->
                        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm md:hidden">
                            <ul class="divide-y divide-neutral-200">
                                <li
                                    v-for="category in rows"
                                    :key="category.id"
                                    class="space-y-1 p-4"
                                    :class="!category.is_active && 'bg-neutral-50'"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="min-w-0 break-words text-sm font-medium text-neutral-900">{{ category.name }}</p>
                                        <Badge :tone="category.is_active ? 'success' : 'neutral'" class="shrink-0">
                                            {{ category.is_active ? 'Ativa' : 'Inativa' }}
                                        </Badge>
                                    </div>
                                    <p v-if="category.description" class="line-clamp-3 break-words text-sm text-neutral-600">
                                        {{ category.description }}
                                    </p>
                                    <p v-else class="text-sm text-neutral-500">Sem descrição</p>
                                    <p class="text-xs text-neutral-500">Atualizada em {{ formatShortDate(category.updated_at) }}</p>
                                    <Button
                                        v-if="category.can_edit"
                                        variant="secondary"
                                        block
                                        class="!mt-3"
                                        :href="route('admin.categories.edit', category.id)"
                                        :aria-label="`Editar ${category.name}`"
                                    >
                                        <Icon name="pencil" :size="16" /> Editar
                                    </Button>
                                </li>
                            </ul>
                            <Pagination
                                :page="categories.current_page"
                                :per-page="categories.per_page"
                                :total="categories.total"
                                @update:page="goToPage"
                            />
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
