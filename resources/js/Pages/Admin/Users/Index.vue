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
import { adminHomeHref } from '@/lib/navigation';
import type { Option } from '@/lib/ticket';
import {
    formatShortDate,
    userFilterKeys,
    type UserFilterKey,
    type UserFilterOptions,
    type UserFilters,
    type UserListItem,
    type UserSortKey,
} from '@/lib/user';
import type { Paginator } from '@/types';

const props = defineProps<{
    users: Paginator<UserListItem>;
    filters: UserFilters;
    sort: { key: UserSortKey; direction: Direction };
    options: UserFilterOptions;
    can: { create: boolean };
}>();

const page = usePage();

const sortLabels: Record<UserSortKey, string> = {
    name: 'Nome',
    email: 'E-mail',
    created_at: 'Data de criação',
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
    routeName: 'admin.users.index',
    filterKeys: userFilterKeys,
    filters: () => props.filters,
    sort: () => props.sort,
    defaultSort: { key: 'name', direction: 'asc' },
    descendingFirst: ['created_at'],
});

/* ---------- Filtros ativos ---------- */

const optionLabel = (options: Option[], value: string) => options.find((o) => o.value === value)?.label ?? value;

const chipLabel = (key: UserFilterKey, value: string): string => {
    const o = props.options;
    switch (key) {
        case 'q':
            return `Pesquisa: “${value}”`;
        case 'role':
            return `Papel: ${value === 'none' ? 'Sem papel' : optionLabel(o.roles, value)}`;
        case 'department':
            return `Departamento: ${value === 'none' ? 'Sem departamento' : optionLabel(o.departments, value)}`;
        case 'status':
            return `Situação: ${optionLabel(o.statuses, value)}`;
    }
};

const activeChips = computed(() =>
    userFilterKeys
        .filter((key) => props.filters[key])
        .map((key) => ({ key, label: chipLabel(key, props.filters[key]!) })),
);

/* ---------- Resultados ---------- */

const rows = computed(() => props.users.data);
const isEmpty = computed(() => rows.value.length === 0);
/** A coluna de ação só aparece quando há algum usuário que o operador pode editar (ADM-03). */
const showEdit = computed(() => rows.value.some((user) => user.can_edit));
const currentUserId = computed(() => page.props.auth.user.id);

const breadcrumbs = computed(() => [
    { label: 'Administração', href: adminHomeHref(page.props.auth.permissions) },
    { label: 'Usuários' },
]);
</script>

<template>
    <Head title="Usuários" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Usuários" subtitle="Consulte e gerencie os usuários cadastrados no sistema.">
                    <Button v-if="can.create" :href="route('admin.users.create')">
                        <Icon name="plus" :size="16" /> Novo usuário
                    </Button>
                </PageHeader>
            </div>

            <AdminSectionNav />

            <!-- Pesquisa e filtros -->
            <Card>
                <form role="search" aria-label="Pesquisa e filtros de usuários" @submit.prevent="submitFilters">
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <div class="min-w-0 flex-1">
                            <label for="user-search" class="sr-only">Pesquisar usuários</label>
                            <SearchInput
                                id="user-search"
                                v-model="form.q"
                                placeholder="Buscar por nome ou e-mail..."
                                maxlength="100"
                                :aria-invalid="serverErrors.q ? 'true' : undefined"
                                :aria-describedby="serverErrors.q ? 'user-search-error' : undefined"
                            />
                            <p v-if="serverErrors.q" id="user-search-error" class="mt-2 text-sm text-danger-text">
                                {{ serverErrors.q }}
                            </p>
                        </div>
                        <Button type="submit" class="sm:flex-none" :disabled="processing">Filtrar</Button>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <FormField label="Papel" :error="serverErrors.role" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.role" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todos</option>
                                <option value="none">Sem papel</option>
                                <option v-for="o in options.roles" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>

                        <FormField label="Departamento" :error="serverErrors.department" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.department" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todos</option>
                                <option value="none">Sem departamento</option>
                                <option v-for="o in options.departments" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>

                        <FormField label="Situação" :error="serverErrors.status" v-slot="{ id, describedby, invalid }">
                            <Select :id="id" v-model="form.status" :aria-describedby="describedby" :invalid="invalid">
                                <option value="">Todas</option>
                                <option v-for="o in options.statuses" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <!-- Filtros ativos: cada um pode ser removido individualmente -->
                        <div v-if="activeChips.length" class="min-w-0">
                            <p id="active-user-filters-label" class="sr-only">Filtros ativos</p>
                            <ul class="flex flex-wrap gap-2" aria-labelledby="active-user-filters-label">
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

            <!-- Confirmação vinda de outra tela, como a criação (ADM-02), só depois da gravação real -->
            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar os usuários">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="retry">Tentar novamente</Button>
            </Alert>

            <!-- Resultados -->
            <section aria-labelledby="user-results-title" :aria-busy="processing">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="user-results-title" class="text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ users.total }}</span>
                        {{ users.total === 1 ? 'usuário encontrado' : 'usuários encontrados' }}
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
                            <label for="user-sort" class="text-xs font-medium text-neutral-700">Ordenar por</label>
                            <Select id="user-sort" v-model="mobileSort" class="mt-1" :disabled="processing">
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
                            title="Nenhum usuário corresponde aos critérios"
                            description="Revise a pesquisa ou os filtros aplicados."
                        >
                            <Button variant="secondary" :disabled="processing" @click="clearFilters">Limpar filtros</Button>
                        </EmptyState>
                        <EmptyState v-else icon="users" title="Nenhum usuário cadastrado" description="Ainda não há usuários no sistema.">
                            <Button v-if="can.create" :href="route('admin.users.create')">
                                <Icon name="plus" :size="16" /> Cadastrar usuário
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
                                        <SortableHeader label="E-mail" :direction="direction('email')" @sort="sortBy('email')" />
                                        <th scope="col">Papel</th>
                                        <th scope="col">Departamento</th>
                                        <th scope="col">Situação</th>
                                        <SortableHeader label="Criação" :direction="direction('created_at')" @sort="sortBy('created_at')" />
                                        <th v-if="showEdit" scope="col"><span class="sr-only">Ações</span></th>
                                    </tr>
                                </template>

                                <tr v-for="user in rows" :key="user.id">
                                    <td class="min-w-[12rem] font-medium">
                                        {{ user.name }}
                                        <span v-if="user.id === currentUserId" class="text-xs font-normal text-neutral-500">(você)</span>
                                    </td>
                                    <td class="break-all text-neutral-600">{{ user.email }}</td>
                                    <td class="whitespace-nowrap">
                                        <span v-if="user.role" class="text-neutral-600">{{ user.role }}</span>
                                        <span v-else class="text-neutral-500">Sem papel</span>
                                    </td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ user.department ?? '—' }}</td>
                                    <td>
                                        <Badge :tone="user.is_active ? 'success' : 'neutral'">
                                            {{ user.is_active ? 'Ativo' : 'Inativo' }}
                                        </Badge>
                                    </td>
                                    <td class="whitespace-nowrap text-neutral-600">{{ formatShortDate(user.created_at) }}</td>
                                    <td v-if="showEdit" class="whitespace-nowrap text-right">
                                        <Button
                                            v-if="user.can_edit"
                                            variant="ghost"
                                            size="sm"
                                            :href="route('admin.users.edit', user.id)"
                                            :aria-label="`Editar ${user.name}`"
                                        >
                                            <Icon name="pencil" :size="16" /> Editar
                                        </Button>
                                    </td>
                                </tr>

                                <template #footer>
                                    <Pagination
                                        :page="users.current_page"
                                        :per-page="users.per_page"
                                        :total="users.total"
                                        @update:page="goToPage"
                                    />
                                </template>
                            </DataTable>
                        </div>

                        <!-- Celular: cartões compactos -->
                        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm md:hidden">
                            <ul class="divide-y divide-neutral-200">
                                <li v-for="user in rows" :key="user.id" class="space-y-1 p-4">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="min-w-0 break-words text-sm font-medium text-neutral-900">
                                            {{ user.name }}
                                            <span v-if="user.id === currentUserId" class="text-xs font-normal text-neutral-500">(você)</span>
                                        </p>
                                        <Badge :tone="user.is_active ? 'success' : 'neutral'" class="shrink-0">
                                            {{ user.is_active ? 'Ativo' : 'Inativo' }}
                                        </Badge>
                                    </div>
                                    <p class="break-all text-sm text-neutral-600">{{ user.email }}</p>
                                    <p class="text-xs text-neutral-500">
                                        {{ user.role ?? 'Sem papel' }} · {{ user.department ?? 'Sem departamento' }} · Criado em
                                        {{ formatShortDate(user.created_at) }}
                                    </p>
                                    <Button
                                        v-if="user.can_edit"
                                        variant="secondary"
                                        block
                                        class="!mt-3"
                                        :href="route('admin.users.edit', user.id)"
                                        :aria-label="`Editar ${user.name}`"
                                    >
                                        <Icon name="pencil" :size="16" /> Editar
                                    </Button>
                                </li>
                            </ul>
                            <Pagination
                                :page="users.current_page"
                                :per-page="users.per_page"
                                :total="users.total"
                                @update:page="goToPage"
                            />
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
