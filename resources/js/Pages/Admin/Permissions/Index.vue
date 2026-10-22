<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
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
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Select from '@/Components/Select.vue';
import SortableHeader from '@/Components/SortableHeader.vue';
import Spinner from '@/Components/Spinner.vue';
import StatCard from '@/Components/StatCard.vue';
import { useListing, type Direction } from '@/composables/useListing';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { adminHomeHref } from '@/lib/navigation';
import {
    permissionFilterKeys,
    type PermissionDetail,
    type PermissionFilterKey,
    type PermissionFilterOptions,
    type PermissionFilters,
    type PermissionListItem,
    type PermissionSortKey,
    type PermissionSummary,
} from '@/lib/permission';
import type { Paginator } from '@/types';

const props = defineProps<{
    permissions: Paginator<PermissionListItem>;
    summary: PermissionSummary;
    filters: PermissionFilters;
    sort: { key: PermissionSortKey; direction: Direction };
    options: PermissionFilterOptions;
    detail: PermissionDetail | null;
    /** Identificador pedido para o painel; com `detail` nulo, não foi encontrado. */
    selected: number | null;
}>();

const page = usePage();

const sortLabels: Record<PermissionSortKey, string> = {
    name: 'Nome',
    slug: 'Identificador',
};

const defaultSort = { key: 'name', direction: 'asc' } as const;

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
    routeName: 'admin.permissions.index',
    filterKeys: permissionFilterKeys,
    filters: () => props.filters,
    sort: () => props.sort,
    defaultSort,
});

/** O módulo é aplicado assim que o usuário o escolhe, mantendo a pesquisa digitada. */
const applyModule = (module: string | number | null) => {
    form.module = String(module ?? '');
    submitFilters();
};

/* ---------- Filtros ativos ---------- */

const chipLabel = (key: PermissionFilterKey, value: string): string => {
    switch (key) {
        case 'q':
            return `Pesquisa: “${value}”`;
        case 'module':
            return `Módulo: ${props.options.modules.find((o) => o.value === value)?.label ?? value}`;
    }
};

const activeChips = computed(() =>
    permissionFilterKeys
        .filter((key) => props.filters[key])
        .map((key) => ({ key, label: chipLabel(key, props.filters[key]!) })),
);

/* ---------- Detalhes ---------- */

const loadingDetail = ref(false);

/** Parâmetros da listagem atual, para abrir e fechar o painel sem perder filtros nem página. */
const listingParams = () => {
    const isDefaultSort = props.sort.key === defaultSort.key && props.sort.direction === defaultSort.direction;

    return {
        ...props.filters,
        ...(isDefaultSort ? {} : { sort: props.sort.key, direction: props.sort.direction }),
        ...(props.permissions.current_page > 1 ? { page: props.permissions.current_page } : {}),
    };
};

const visitDetail = (selected?: number) =>
    router.get(route('admin.permissions.index'), { ...listingParams(), ...(selected ? { selected } : {}) }, {
        preserveState: true,
        preserveScroll: true,
        only: ['detail', 'selected'],
        onStart: () => (loadingDetail.value = true),
        onFinish: () => (loadingDetail.value = false),
    });

const openDetail = (permission: PermissionListItem) => visitDetail(permission.id);
const closeDetail = () => visitDetail();

/* ---------- Resultados ---------- */

const rows = computed(() => props.permissions.data);
const isEmpty = computed(() => rows.value.length === 0);
/** Catálogo vazio, e não apenas uma pesquisa sem resultados. */
const hasNoPermissions = computed(() => props.summary.total === 0);
const formatCount = (value: number) => value.toLocaleString('pt-BR');
const count = (value: number, singular: string, plural: string) => `${formatCount(value)} ${value === 1 ? singular : plural}`;

const breadcrumbs = computed(() => [
    { label: 'Administração', href: adminHomeHref(page.props.auth.permissions) },
    { label: 'Papéis e permissões', href: route('admin.roles.index') },
    { label: 'Catálogo de permissões' },
]);
</script>

<template>
    <Head title="Catálogo de permissões" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Catálogo de permissões" subtitle="Consulte as permissões conhecidas pelo sistema.">
                    <Button variant="secondary" :href="route('admin.roles.index')">
                        <Icon name="chevron-left" :size="16" /> Papéis
                    </Button>
                    <Button v-if="route().has('admin.roles.permissions.edit')" variant="secondary" :href="route('admin.roles.permissions.edit')">
                        <Icon name="shield-check" :size="16" /> Matriz de permissões
                    </Button>
                </PageHeader>
            </div>

            <AdminSectionNav />

            <!-- Indicadores: catálogo inteiro, independentemente da pesquisa -->
            <section aria-label="Resumo do catálogo" class="grid gap-4 sm:grid-cols-3">
                <StatCard label="Permissões conhecidas" :value="formatCount(summary.total)" hint="Total do catálogo" />
                <StatCard label="Módulos" :value="formatCount(summary.modules)" hint="Agrupados pelo prefixo do identificador" />
                <StatCard label="Administrativas" :value="formatCount(summary.administrative)" hint="Só concedidas por quem as possui" />
            </section>

            <!-- Pesquisa e filtro por módulo -->
            <Card>
                <form role="search" aria-label="Pesquisa e filtros de permissões" @submit.prevent="submitFilters">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <label for="permission-search" class="text-sm font-medium text-neutral-700">Pesquisar</label>
                            <div class="mt-1">
                                <SearchInput
                                    id="permission-search"
                                    v-model="form.q"
                                    placeholder="Nome, identificador, descrição ou módulo..."
                                    maxlength="100"
                                    :aria-invalid="serverErrors.q ? 'true' : undefined"
                                    :aria-describedby="serverErrors.q ? 'permission-search-error' : undefined"
                                />
                            </div>
                            <p v-if="serverErrors.q" id="permission-search-error" class="mt-1 text-sm text-danger-text">
                                {{ serverErrors.q }}
                            </p>
                        </div>
                        <div v-if="options.modules.length" class="sm:w-56">
                            <FormField label="Módulo" :error="serverErrors.module" v-slot="{ id, describedby, invalid }">
                                <Select
                                    :id="id"
                                    :model-value="form.module"
                                    :aria-describedby="describedby"
                                    :invalid="invalid"
                                    :disabled="processing"
                                    @update:model-value="applyModule"
                                >
                                    <option value="">Todos</option>
                                    <option v-for="o in options.modules" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </Select>
                            </FormField>
                        </div>
                        <Button type="submit" :disabled="processing">Pesquisar</Button>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div v-if="activeChips.length" class="min-w-0">
                            <p id="active-permission-filters-label" class="sr-only">Filtros ativos</p>
                            <ul class="flex flex-wrap gap-2" aria-labelledby="active-permission-filters-label">
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

            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar o catálogo">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="retry">Tentar novamente</Button>
            </Alert>

            <!-- Resultados -->
            <section aria-labelledby="permission-results-title" :aria-busy="processing">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="permission-results-title" class="text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ formatCount(permissions.total) }}</span>
                        {{ permissions.total === 1 ? 'permissão encontrada' : 'permissões encontradas' }}
                    </h2>
                    <p v-if="processing" class="inline-flex items-center gap-2 text-sm text-neutral-600" role="status" aria-live="polite">
                        <Spinner /> Atualizando resultados…
                    </p>

                    <!-- Ordenação no celular, onde a tabela vira cartões -->
                    <div v-if="!isEmpty" class="flex w-full items-end gap-2 md:hidden">
                        <div class="min-w-0 flex-1">
                            <label for="permission-sort" class="text-xs font-medium text-neutral-700">Ordenar por</label>
                            <Select id="permission-sort" v-model="mobileSort" class="mt-1" :disabled="processing">
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
                            v-if="hasNoPermissions"
                            icon="shield-check"
                            title="Nenhuma permissão cadastrada"
                            description="O catálogo de permissões do sistema está vazio."
                        />
                        <EmptyState
                            v-else-if="hasAppliedFilters"
                            icon="search"
                            title="Nenhuma permissão encontrada"
                            description="Nenhuma permissão corresponde à pesquisa ou ao módulo escolhido."
                        >
                            <Button variant="secondary" :disabled="processing" @click="clearFilters">Limpar filtros</Button>
                        </EmptyState>
                        <EmptyState v-else icon="search" title="Nenhuma permissão encontrada" description="Esta página não tem resultados.">
                            <Button variant="secondary" :disabled="processing" @click="goToPage(1)">Ir para a primeira página</Button>
                        </EmptyState>
                    </Card>

                    <template v-else>
                        <!-- Tablet e desktop: tabela -->
                        <div class="hidden md:block">
                            <DataTable>
                                <template #head>
                                    <tr class="whitespace-nowrap">
                                        <SortableHeader label="Permissão" :direction="direction('name')" @sort="sortBy('name')" />
                                        <SortableHeader label="Identificador" :direction="direction('slug')" @sort="sortBy('slug')" />
                                        <th scope="col">Módulo</th>
                                        <th scope="col">Descrição</th>
                                        <th scope="col" class="text-right">Papéis</th>
                                        <th scope="col"><span class="sr-only">Ações</span></th>
                                    </tr>
                                </template>

                                <tr v-for="permission in rows" :key="permission.id">
                                    <td class="min-w-[12rem]">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium">{{ permission.name }}</span>
                                            <Badge v-if="permission.administrative" tone="warning">Administrativa</Badge>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap font-mono text-xs text-neutral-600">{{ permission.slug }}</td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ permission.group_label }}</td>
                                    <td class="min-w-[12rem] max-w-sm text-neutral-600">
                                        <p v-if="permission.description" class="line-clamp-2 break-words" :title="permission.description">
                                            {{ permission.description }}
                                        </p>
                                        <span v-else class="text-neutral-500">Sem descrição</span>
                                    </td>
                                    <td class="whitespace-nowrap text-right tabular-nums text-neutral-600">{{ formatCount(permission.roles_count) }}</td>
                                    <td class="whitespace-nowrap text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            :disabled="loadingDetail"
                                            :aria-label="`Ver detalhes de ${permission.name}`"
                                            @click="openDetail(permission)"
                                        >
                                            Detalhes
                                        </Button>
                                    </td>
                                </tr>

                                <template #footer>
                                    <Pagination
                                        :page="permissions.current_page"
                                        :per-page="permissions.per_page"
                                        :total="permissions.total"
                                        @update:page="goToPage"
                                    />
                                </template>
                            </DataTable>
                        </div>

                        <!-- Celular: cartões compactos -->
                        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm md:hidden">
                            <ul class="divide-y divide-neutral-200">
                                <li v-for="permission in rows" :key="permission.id" class="space-y-1 p-4">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="min-w-0 break-words text-sm font-medium text-neutral-900">{{ permission.name }}</p>
                                        <Badge v-if="permission.administrative" tone="warning" class="shrink-0">Administrativa</Badge>
                                    </div>
                                    <p class="break-all font-mono text-xs text-neutral-500">{{ permission.slug }}</p>
                                    <p class="text-xs text-neutral-500">
                                        {{ permission.group_label }} · {{ count(permission.roles_count, 'papel', 'papéis') }}
                                    </p>
                                    <Button
                                        variant="secondary"
                                        block
                                        class="!mt-3"
                                        :disabled="loadingDetail"
                                        :aria-label="`Ver detalhes de ${permission.name}`"
                                        @click="openDetail(permission)"
                                    >
                                        Detalhes
                                    </Button>
                                </li>
                            </ul>
                            <Pagination
                                :page="permissions.current_page"
                                :per-page="permissions.per_page"
                                :total="permissions.total"
                                @update:page="goToPage"
                            />
                        </div>
                    </template>
                </div>
            </section>
        </div>

        <!-- Detalhes: somente leitura -->
        <Modal :show="selected !== null" max-width="lg" @close="closeDetail">
            <div class="p-6">
                <template v-if="detail">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="break-words text-lg font-semibold text-neutral-900">{{ detail.name }}</h2>
                            <p class="mt-1 break-all font-mono text-xs text-neutral-500">{{ detail.slug }}</p>
                        </div>
                        <Badge v-if="detail.administrative" tone="warning" class="shrink-0">Administrativa</Badge>
                    </div>

                    <dl class="mt-6 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-neutral-700">Módulo</dt>
                            <dd class="mt-1 text-neutral-600">{{ detail.group_label }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-neutral-700">Descrição</dt>
                            <dd class="mt-1 break-words text-neutral-600">{{ detail.description ?? 'Sem descrição cadastrada.' }}</dd>
                        </div>
                        <div v-if="detail.administrative">
                            <dt class="font-medium text-neutral-700">Concessão</dt>
                            <dd class="mt-1 text-neutral-600">
                                Permissão administrativa: só pode ser concedida ou retirada por quem também a possui.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-neutral-700">Papéis com esta permissão</dt>
                            <dd class="mt-1">
                                <ul v-if="detail.roles.length" class="flex flex-wrap gap-2">
                                    <li v-for="role in detail.roles" :key="role.id">
                                        <Badge :tone="role.is_system ? 'neutral' : 'primary'">{{ role.name }}</Badge>
                                    </li>
                                </ul>
                                <p v-else class="text-neutral-500">Nenhum papel recebe esta permissão.</p>
                                <p class="mt-2 text-xs text-neutral-500">
                                    Estar no catálogo não significa que um usuário pode executar a ação: isso depende do papel dele e das
                                    regras de cada operação.
                                </p>
                            </dd>
                        </div>
                    </dl>
                </template>

                <template v-else>
                    <h2 class="text-lg font-semibold text-neutral-900">Permissão não encontrada</h2>
                    <p class="mt-2 text-sm text-neutral-600">A permissão solicitada não existe no catálogo.</p>
                </template>

                <div class="mt-6 flex justify-end">
                    <Button variant="secondary" :loading="loadingDetail" @click="closeDetail">Fechar</Button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
