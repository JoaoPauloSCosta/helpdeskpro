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
import { adminHomeHref } from '@/lib/navigation';
import { roleFilterKeys, type RoleFilters, type RoleListItem, type RoleSortKey, type RoleSummary } from '@/lib/role';
import type { Paginator } from '@/types';

const props = defineProps<{
    roles: Paginator<RoleListItem>;
    summary: RoleSummary;
    filters: RoleFilters;
    sort: { key: RoleSortKey; direction: Direction };
    can: { create: boolean; matrix: boolean; catalog: boolean };
}>();

const page = usePage();

const sortLabels: Record<RoleSortKey, string> = {
    name: 'Nome',
    users_count: 'Usuários associados',
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
    sortBy,
    goToPage,
    direction,
    mobileSort,
} = useListing({
    routeName: 'admin.roles.index',
    filterKeys: roleFilterKeys,
    filters: () => props.filters,
    sort: () => props.sort,
    defaultSort: { key: 'name', direction: 'asc' },
    descendingFirst: ['users_count'],
});

/* ---------- Resultados ---------- */

const rows = computed(() => props.roles.data);
const isEmpty = computed(() => rows.value.length === 0);
/** Base vazia, e não apenas uma pesquisa sem resultados. */
const hasNoRoles = computed(() => props.summary.total === 0);
/** A coluna de ação só aparece quando há algum papel que o operador pode editar (PER-03). */
const showEdit = computed(() => rows.value.some((role) => role.can_edit));
const showActions = computed(() => showEdit.value || props.can.matrix);
const successMessage = computed(() => page.props.flash?.success ?? null);
const formatCount = (value: number) => value.toLocaleString('pt-BR');
const count = (value: number, singular: string, plural: string) => `${formatCount(value)} ${value === 1 ? singular : plural}`;

const breadcrumbs = computed(() => [
    { label: 'Administração', href: adminHomeHref(page.props.auth.permissions) },
    { label: 'Papéis e permissões' },
]);
</script>

<template>
    <Head title="Papéis" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Papéis" subtitle="Consulte os papéis cadastrados e suas associações no sistema.">
                    <Button v-if="can.catalog" variant="secondary" :href="route('admin.permissions.index')">
                        <Icon name="search" :size="16" /> Catálogo de permissões
                    </Button>
                    <Button v-if="can.matrix" variant="secondary" :href="route('admin.roles.permissions.edit')">
                        <Icon name="shield-check" :size="16" /> Matriz de permissões
                    </Button>
                    <Button v-if="can.create" :href="route('admin.roles.create')">
                        <Icon name="plus" :size="16" /> Novo papel
                    </Button>
                </PageHeader>
            </div>

            <AdminSectionNav />

            <!-- Indicadores: todos os papéis, independentemente da pesquisa -->
            <section aria-label="Resumo dos papéis" class="grid gap-4 sm:grid-cols-3">
                <StatCard label="Total de papéis" :value="formatCount(summary.total)" />
                <StatCard label="Papéis do sistema" :value="formatCount(summary.system)" hint="Criados pela matriz inicial" />
                <StatCard label="Usuários associados" :value="formatCount(summary.users)" hint="Ativos e inativos" />
            </section>

            <!-- Pesquisa -->
            <Card>
                <form role="search" aria-label="Pesquisa de papéis" @submit.prevent="submitFilters">
                    <label for="role-search" class="text-sm font-medium text-neutral-700">Pesquisar</label>
                    <!-- Só o campo e os botões na mesma linha, para ficarem alinhados pela altura do campo -->
                    <div class="mt-1 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1">
                            <SearchInput
                                id="role-search"
                                v-model="form.q"
                                placeholder="Nome do papel..."
                                maxlength="100"
                                :aria-invalid="serverErrors.q ? 'true' : undefined"
                                :aria-describedby="serverErrors.q ? 'role-search-error' : 'role-search-help'"
                            />
                        </div>
                        <div class="flex shrink-0 gap-3">
                            <Button type="submit" :disabled="processing">Pesquisar</Button>
                            <Button
                                variant="ghost"
                                :disabled="processing || (!hasAppliedFilters && formIsEmpty)"
                                @click="clearFilters"
                            >
                                Limpar
                            </Button>
                        </div>
                    </div>
                    <p v-if="serverErrors.q" id="role-search-error" class="mt-1 text-sm text-danger-text">
                        {{ serverErrors.q }}
                    </p>
                    <p v-else id="role-search-help" class="mt-1 text-xs text-neutral-500">Pesquisa no nome e na descrição.</p>
                </form>
            </Card>

            <!-- Confirmação vinda de outra tela, só depois da gravação real -->
            <Alert v-if="successMessage" variant="success">{{ successMessage }}</Alert>

            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar os papéis">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="retry">Tentar novamente</Button>
            </Alert>

            <!-- Resultados -->
            <section aria-labelledby="role-results-title" :aria-busy="processing">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="role-results-title" class="text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ formatCount(roles.total) }}</span>
                        {{ roles.total === 1 ? 'papel encontrado' : 'papéis encontrados' }}
                        <template v-if="filters.q"> para “{{ filters.q }}”</template>
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
                            <label for="role-sort" class="text-xs font-medium text-neutral-700">Ordenar por</label>
                            <Select id="role-sort" v-model="mobileSort" class="mt-1" :disabled="processing">
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
                            v-if="hasNoRoles"
                            icon="users"
                            title="Nenhum papel cadastrado"
                            description="Ainda não há papéis para associar aos usuários."
                        >
                            <Button v-if="can.create" :href="route('admin.roles.create')">
                                <Icon name="plus" :size="16" /> Cadastrar papel
                            </Button>
                        </EmptyState>
                        <EmptyState
                            v-else-if="hasAppliedFilters"
                            icon="search"
                            title="Nenhum papel encontrado"
                            description="Nenhum papel corresponde ao termo pesquisado."
                        >
                            <Button variant="secondary" :disabled="processing" @click="clearFilters">Limpar pesquisa</Button>
                        </EmptyState>
                        <!-- Página além da última, por exemplo depois de um link antigo -->
                        <EmptyState
                            v-else
                            icon="search"
                            title="Nenhum papel encontrado"
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
                                        <SortableHeader label="Papel" :direction="direction('name')" @sort="sortBy('name')" />
                                        <th scope="col">Descrição</th>
                                        <th scope="col" class="text-right">Permissões</th>
                                        <SortableHeader
                                            label="Usuários associados"
                                            class="text-right"
                                            :direction="direction('users_count')"
                                            @sort="sortBy('users_count')"
                                        />
                                        <th v-if="showActions" scope="col"><span class="sr-only">Ações</span></th>
                                    </tr>
                                </template>

                                <tr v-for="role in rows" :key="role.id">
                                    <td class="min-w-[12rem]">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium">{{ role.name }}</span>
                                            <Badge v-if="role.is_system" tone="neutral">Sistema</Badge>
                                        </div>
                                        <p class="mt-0.5 font-mono text-xs text-neutral-500">{{ role.slug }}</p>
                                    </td>
                                    <td class="min-w-[16rem] max-w-md text-neutral-600">
                                        <p v-if="role.description" class="line-clamp-2 break-words" :title="role.description">
                                            {{ role.description }}
                                        </p>
                                        <span v-else class="text-neutral-500">Sem descrição</span>
                                    </td>
                                    <td class="whitespace-nowrap text-right tabular-nums text-neutral-600">
                                        {{ formatCount(role.permissions_count) }}
                                    </td>
                                    <td class="whitespace-nowrap text-right tabular-nums text-neutral-600">
                                        {{ formatCount(role.users_count) }}
                                    </td>
                                    <td v-if="showActions" class="whitespace-nowrap text-right">
                                        <Button
                                            v-if="can.matrix"
                                            variant="ghost"
                                            size="sm"
                                            :href="route('admin.roles.permissions.edit', { role: role.id })"
                                            :aria-label="`Permissões de ${role.name}`"
                                        >
                                            <Icon name="shield-check" :size="16" /> Permissões
                                        </Button>
                                        <Button
                                            v-if="role.can_edit"
                                            variant="ghost"
                                            size="sm"
                                            :href="route('admin.roles.edit', role.id)"
                                            :aria-label="`Editar ${role.name}`"
                                        >
                                            <Icon name="pencil" :size="16" /> Editar
                                        </Button>
                                    </td>
                                </tr>

                                <template #footer>
                                    <Pagination
                                        :page="roles.current_page"
                                        :per-page="roles.per_page"
                                        :total="roles.total"
                                        @update:page="goToPage"
                                    />
                                </template>
                            </DataTable>
                        </div>

                        <!-- Celular: cartões compactos -->
                        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm md:hidden">
                            <ul class="divide-y divide-neutral-200">
                                <li v-for="role in rows" :key="role.id" class="space-y-1 p-4">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="min-w-0 break-words text-sm font-medium text-neutral-900">{{ role.name }}</p>
                                        <Badge v-if="role.is_system" tone="neutral" class="shrink-0">Sistema</Badge>
                                    </div>
                                    <p class="break-all font-mono text-xs text-neutral-500">{{ role.slug }}</p>
                                    <p v-if="role.description" class="line-clamp-3 break-words text-sm text-neutral-600">
                                        {{ role.description }}
                                    </p>
                                    <p v-else class="text-sm text-neutral-500">Sem descrição</p>
                                    <p class="text-xs text-neutral-500">
                                        {{ count(role.permissions_count, 'permissão', 'permissões') }} ·
                                        {{ count(role.users_count, 'usuário associado', 'usuários associados') }}
                                    </p>
                                    <div v-if="can.matrix || role.can_edit" class="!mt-3 grid gap-2 sm:grid-cols-2">
                                        <Button
                                            v-if="can.matrix"
                                            variant="secondary"
                                            block
                                            :href="route('admin.roles.permissions.edit', { role: role.id })"
                                            :aria-label="`Permissões de ${role.name}`"
                                        >
                                            <Icon name="shield-check" :size="16" /> Permissões
                                        </Button>
                                        <Button
                                            v-if="role.can_edit"
                                            variant="secondary"
                                            block
                                            :href="route('admin.roles.edit', role.id)"
                                            :aria-label="`Editar ${role.name}`"
                                        >
                                            <Icon name="pencil" :size="16" /> Editar
                                        </Button>
                                    </div>
                                </li>
                            </ul>
                            <Pagination
                                :page="roles.current_page"
                                :per-page="roles.per_page"
                                :total="roles.total"
                                @update:page="goToPage"
                            />
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
