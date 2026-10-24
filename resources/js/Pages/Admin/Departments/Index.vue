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
import { useListing, type Direction } from '@/composables/useListing';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    departmentFilterKeys,
    type DepartmentFilterKey,
    type DepartmentFilterOptions,
    type DepartmentFilters,
    type DepartmentListItem,
    type DepartmentSortKey,
} from '@/lib/department';
import { adminHomeHref } from '@/lib/navigation';
import { formatShortDate } from '@/lib/user';
import type { Paginator } from '@/types';

const props = defineProps<{
    departments: Paginator<DepartmentListItem>;
    filters: DepartmentFilters;
    sort: { key: DepartmentSortKey; direction: Direction };
    options: DepartmentFilterOptions;
    can: { create: boolean };
}>();

const page = usePage();

const sortLabels: Record<DepartmentSortKey, string> = {
    name: 'Nome',
    created_at: 'Data de criação',
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
    routeName: 'admin.departments.index',
    filterKeys: departmentFilterKeys,
    filters: () => props.filters,
    sort: () => props.sort,
    defaultSort: { key: 'name', direction: 'asc' },
    descendingFirst: ['created_at', 'updated_at'],
});

/* ---------- Filtros ativos ---------- */

const chipLabel = (key: DepartmentFilterKey, value: string): string => {
    switch (key) {
        case 'q':
            return `Pesquisa: “${value}”`;
        case 'status':
            return `Situação: ${props.options.statuses.find((o) => o.value === value)?.label ?? value}`;
    }
};

const activeChips = computed(() =>
    departmentFilterKeys
        .filter((key) => props.filters[key])
        .map((key) => ({ key, label: chipLabel(key, props.filters[key]!) })),
);

/* ---------- Resultados ---------- */

const rows = computed(() => props.departments.data);
const isEmpty = computed(() => rows.value.length === 0);
/** A coluna de ação só aparece quando há algum departamento que o operador pode editar (ADM-06). */
const showEdit = computed(() => rows.value.some((department) => department.can_edit));
const count = (value: number, singular: string, plural: string) =>
    `${value.toLocaleString('pt-BR')} ${value === 1 ? singular : plural}`;

const breadcrumbs = computed(() => [
    { label: 'Administração', href: adminHomeHref(page.props.auth.permissions) },
    { label: 'Departamentos' },
]);
</script>

<template>
    <Head title="Departamentos" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Departamentos" subtitle="Consulte os departamentos cadastrados, ativos e inativos.">
                    <Button v-if="can.create" :href="route('admin.departments.create')">
                        <Icon name="plus" :size="16" /> Novo departamento
                    </Button>
                </PageHeader>
            </div>

            <AdminSectionNav />

            <!-- Pesquisa e filtros -->
            <Card>
                <form role="search" aria-label="Pesquisa e filtros de departamentos" @submit.prevent="submitFilters">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <label for="department-search" class="text-sm font-medium text-neutral-700">Pesquisar</label>
                            <div class="mt-1">
                                <SearchInput
                                    id="department-search"
                                    v-model="form.q"
                                    placeholder="Buscar por nome ou descrição..."
                                    maxlength="100"
                                    :aria-invalid="serverErrors.q ? 'true' : undefined"
                                    :aria-describedby="serverErrors.q ? 'department-search-error' : undefined"
                                />
                            </div>
                            <p v-if="serverErrors.q" id="department-search-error" class="mt-1 text-sm text-danger-text">
                                {{ serverErrors.q }}
                            </p>
                        </div>
                        <div class="sm:w-48">
                            <FormField label="Situação" :error="serverErrors.status" v-slot="{ id, describedby, invalid }">
                                <Select :id="id" v-model="form.status" :aria-describedby="describedby" :invalid="invalid">
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
                            <p id="active-department-filters-label" class="sr-only">Filtros ativos</p>
                            <ul class="flex flex-wrap gap-2" aria-labelledby="active-department-filters-label">
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

            <!-- Confirmação vinda de outra tela, como o cadastro (ADM-05), só depois da gravação real -->
            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar os departamentos">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="retry">Tentar novamente</Button>
            </Alert>

            <!-- Resultados -->
            <section aria-labelledby="department-results-title" :aria-busy="processing">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="department-results-title" class="text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ departments.total }}</span>
                        {{ departments.total === 1 ? 'departamento encontrado' : 'departamentos encontrados' }}
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
                            <label for="department-sort" class="text-xs font-medium text-neutral-700">Ordenar por</label>
                            <Select id="department-sort" v-model="mobileSort" class="mt-1" :disabled="processing">
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
                            v-if="hasAppliedFilters"
                            icon="search"
                            title="Nenhum departamento corresponde aos critérios"
                            description="Revise a pesquisa ou os filtros aplicados."
                        >
                            <Button variant="secondary" :disabled="processing" @click="clearFilters">Limpar filtros</Button>
                        </EmptyState>
                        <EmptyState
                            v-else
                            icon="squares"
                            title="Nenhum departamento cadastrado"
                            description="Ainda não há departamentos no sistema."
                        >
                            <Button v-if="can.create" :href="route('admin.departments.create')">
                                <Icon name="plus" :size="16" /> Cadastrar departamento
                            </Button>
                        </EmptyState>
                    </Card>

                    <template v-else>
                        <!-- Tablet e desktop: tabela -->
                        <div class="hidden md:block">
                            <DataTable>
                                <template #head>
                                    <tr class="whitespace-nowrap">
                                        <SortableHeader label="Nome" :direction="direction('name')" @sort="sortBy('name')" />
                                        <th scope="col">Descrição</th>
                                        <th scope="col">Situação</th>
                                        <th scope="col" class="text-right">Usuários</th>
                                        <th scope="col" class="text-right">Chamados</th>
                                        <SortableHeader label="Criação" :direction="direction('created_at')" @sort="sortBy('created_at')" />
                                        <SortableHeader label="Atualização" :direction="direction('updated_at')" @sort="sortBy('updated_at')" />
                                        <th v-if="showEdit" scope="col"><span class="sr-only">Ações</span></th>
                                    </tr>
                                </template>

                                <tr v-for="department in rows" :key="department.id" :class="!department.is_active && 'bg-neutral-50'">
                                    <td class="min-w-[10rem] font-medium" :class="!department.is_active && 'text-neutral-600'">
                                        {{ department.name }}
                                    </td>
                                    <td class="min-w-[16rem] max-w-md text-neutral-600">
                                        <p v-if="department.description" class="line-clamp-2 break-words" :title="department.description">
                                            {{ department.description }}
                                        </p>
                                        <span v-else class="text-neutral-500">Sem descrição</span>
                                    </td>
                                    <td>
                                        <Badge :tone="department.is_active ? 'success' : 'neutral'">
                                            {{ department.is_active ? 'Ativo' : 'Inativo' }}
                                        </Badge>
                                    </td>
                                    <td class="whitespace-nowrap text-right tabular-nums text-neutral-600">
                                        {{ department.users_count.toLocaleString('pt-BR') }}
                                    </td>
                                    <td class="whitespace-nowrap text-right tabular-nums text-neutral-600">
                                        {{ department.tickets_count.toLocaleString('pt-BR') }}
                                    </td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ formatShortDate(department.created_at) }}</td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ formatShortDate(department.updated_at) }}</td>
                                    <td v-if="showEdit" class="whitespace-nowrap text-right">
                                        <Button
                                            v-if="department.can_edit"
                                            variant="ghost"
                                            size="sm"
                                            :href="route('admin.departments.edit', department.id)"
                                            :aria-label="`Editar ${department.name}`"
                                        >
                                            <Icon name="pencil" :size="16" /> Editar
                                        </Button>
                                    </td>
                                </tr>

                                <template #footer>
                                    <Pagination
                                        :page="departments.current_page"
                                        :per-page="departments.per_page"
                                        :total="departments.total"
                                        @update:page="goToPage"
                                    />
                                </template>
                            </DataTable>
                        </div>

                        <!-- Celular: cartões compactos -->
                        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm md:hidden">
                            <ul class="divide-y divide-neutral-200">
                                <li
                                    v-for="department in rows"
                                    :key="department.id"
                                    class="space-y-1 p-4"
                                    :class="!department.is_active && 'bg-neutral-50'"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="min-w-0 break-words text-sm font-medium text-neutral-900">{{ department.name }}</p>
                                        <Badge :tone="department.is_active ? 'success' : 'neutral'" class="shrink-0">
                                            {{ department.is_active ? 'Ativo' : 'Inativo' }}
                                        </Badge>
                                    </div>
                                    <p v-if="department.description" class="line-clamp-3 break-words text-sm text-neutral-600">
                                        {{ department.description }}
                                    </p>
                                    <p v-else class="text-sm text-neutral-500">Sem descrição</p>
                                    <p class="text-xs text-neutral-500">
                                        {{ count(department.users_count, 'usuário', 'usuários') }} ·
                                        {{ count(department.tickets_count, 'chamado', 'chamados') }} · Criado em
                                        {{ formatShortDate(department.created_at) }}
                                    </p>
                                    <Button
                                        v-if="department.can_edit"
                                        variant="secondary"
                                        block
                                        class="!mt-3"
                                        :href="route('admin.departments.edit', department.id)"
                                        :aria-label="`Editar ${department.name}`"
                                    >
                                        <Icon name="pencil" :size="16" /> Editar
                                    </Button>
                                </li>
                            </ul>
                            <Pagination
                                :page="departments.current_page"
                                :per-page="departments.per_page"
                                :total="departments.total"
                                @update:page="goToPage"
                            />
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
