<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AdminSectionNav from '@/Components/AdminSectionNav.vue';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Checkbox from '@/Components/Checkbox.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Select from '@/Components/Select.vue';
import Spinner from '@/Components/Spinner.vue';
import Tabs from '@/Components/Tabs.vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { adminHomeHref } from '@/lib/navigation';
import type { CatalogGroup, CatalogPermission, MatrixView, RoleMatrixTarget, RoleOption } from '@/lib/role';

const props = defineProps<{
    roles: RoleOption[];
    selected: RoleMatrixTarget | null;
    catalog: CatalogGroup[];
}>();

const page = usePage();

/* ---------- Estado persistido e alterações pendentes ---------- */

const sorted = (slugs: string[]) => [...slugs].sort();

const form = useForm({
    permissions: sorted(props.selected?.permissions ?? []),
    version: props.selected?.version ?? '',
});

// Depois de salvar (ou recarregar), o servidor devolve o estado confirmado: ele vira a nova base.
watch(
    () => [props.selected?.id, props.selected?.version],
    () => {
        form.defaults({ permissions: sorted(props.selected?.permissions ?? []), version: props.selected?.version ?? '' });
        form.reset();
    },
);

const persisted = computed(() => new Set(props.selected?.permissions ?? []));
const pending = computed(() => new Set(form.permissions));

const added = computed(() => form.permissions.filter((slug) => !persisted.value.has(slug)));
const removed = computed(() => [...persisted.value].filter((slug) => !pending.value.has(slug)));
const isDirty = computed(() => added.value.length > 0 || removed.value.length > 0);
const changedCount = computed(() => added.value.length + removed.value.length);

const canManage = computed(() => props.selected?.can_manage ?? false);
const isEditable = (permission: CatalogPermission) => canManage.value && permission.grantable;

const toggle = (slug: string, checked: boolean) => {
    const next = new Set(form.permissions);
    if (checked) next.add(slug);
    else next.delete(slug);
    form.permissions = sorted([...next]);
    form.clearErrors('permissions');
    noChanges.value = false;
};

/** Volta à configuração carregada, sem tocar no banco. */
const restore = () => {
    form.reset();
    form.clearErrors();
    noChanges.value = false;
};

/* ---------- Pesquisa e filtros (sobre o catálogo completo, sem perder seleções) ---------- */

const search = ref('');
const view = ref<MatrixView>('all');
const setView = (value: string) => (view.value = value as MatrixView);

const normalize = (value: string) =>
    value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const totalPermissions = computed(() => props.catalog.reduce((sum, group) => sum + group.permissions.length, 0));

const matchesView = (permission: CatalogPermission) =>
    view.value === 'all' || (view.value === 'assigned') === pending.value.has(permission.slug);

const matchesSearch = (permission: CatalogPermission) => {
    const term = normalize(search.value.trim());
    if (!term) return true;

    return [permission.name, permission.slug, permission.description ?? ''].some((text) => normalize(text).includes(term));
};

const visibleGroups = computed(() =>
    props.catalog
        .map((group) => ({ ...group, permissions: group.permissions.filter((p) => matchesView(p) && matchesSearch(p)) }))
        .filter((group) => group.permissions.length > 0),
);

const viewTabs = computed(() => [
    { key: 'all', label: 'Todas', count: totalPermissions.value },
    { key: 'assigned', label: 'Associadas', count: pending.value.size },
    { key: 'unassigned', label: 'Não associadas', count: totalPermissions.value - pending.value.size },
]);

const clearSearch = () => {
    search.value = '';
    view.value = 'all';
};

/* ---------- Seleção do papel ---------- */

const roleChoice = ref<string | number | null>(props.selected?.id ?? '');
watch(
    () => props.selected?.id,
    (id) => (roleChoice.value = id ?? ''),
);

const loadingRole = ref(false);

const chooseRole = (value: string | number | null) => {
    roleChoice.value = value;
    if (!value || Number(value) === props.selected?.id) return;

    router.get(
        route('admin.roles.permissions.edit'),
        { role: value },
        {
            preserveScroll: true,
            onStart: () => (loadingRole.value = true),
            onFinish: () => (loadingRole.value = false),
        },
    );
};

/* ---------- Salvamento ---------- */

// Trocar de papel, cancelar ou sair com alterações pendentes passa pela confirmação.
const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => isDirty.value);

const stay = () => {
    stayOnPage();
    roleChoice.value = props.selected?.id ?? '';
};

const noChanges = ref(false);
const unexpectedError = ref(false);
const errors = computed(() => form.errors as Record<string, string | undefined>);
const permissionsError = computed(
    () => errors.value.permissions ?? Object.entries(errors.value).find(([key]) => key.startsWith('permissions.'))?.[1],
);

const submit = async () => {
    if (form.processing || !props.selected || !canManage.value) return;

    noChanges.value = false;
    if (!isDirty.value) {
        noChanges.value = true;
        return;
    }

    unexpectedError.value = false;
    setSubmitting(true);
    form.put(route('admin.roles.permissions.update', props.selected.id), {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            document.getElementById('matrix-feedback')?.focus();
        },
        onFinish: () => setSubmitting(false),
    });
};

/** Descarta o que foi marcado e carrega o estado atual do servidor. */
const reloadCurrent = () => {
    form.reset();
    form.clearErrors();
    router.reload({ only: ['roles', 'selected'] });
};

const cancel = () => router.visit(route('admin.roles.index'));

// Falhas de rede ou respostas 5xx viram um alerta, sem confirmar nada antes do backend.
const removers: (() => void)[] = [];
onMounted(() => {
    removers.push(
        router.on('exception', (event) => {
            event.preventDefault();
            unexpectedError.value = true;
        }),
        router.on('invalid', (event) => {
            if (event.detail.response.status >= 500) {
                event.preventDefault();
                unexpectedError.value = true;
            }
        }),
    );
});
onUnmounted(() => removers.forEach((remove) => remove()));

/* ---------- Apresentação ---------- */

const successMessage = computed(() => page.props.flash?.success ?? null);
const infoMessage = computed(() => page.props.flash?.info ?? null);

const readOnlyMessage = computed(() => {
    switch (props.selected?.read_only_reason) {
        case 'own_role':
            return 'Este é o seu próprio papel. Para evitar que alguém amplie ou remova o próprio acesso, as permissões dele só podem ser alteradas por outro administrador.';
        case 'not_grantable':
            return 'Este papel tem permissões administrativas que você não possui. Somente quem também as possui pode alterar as permissões deste papel.';
        default:
            return null;
    }
});

const changeState = (slug: string): 'added' | 'removed' | null => {
    if (pending.value.has(slug) && !persisted.value.has(slug)) return 'added';
    if (!pending.value.has(slug) && persisted.value.has(slug)) return 'removed';
    return null;
};

const count = (value: number, singular: string, plural: string) =>
    `${value.toLocaleString('pt-BR')} ${value === 1 ? singular : plural}`;

const breadcrumbs = computed(() => [
    { label: 'Administração', href: adminHomeHref(page.props.auth.permissions) },
    { label: 'Papéis e permissões', href: route('admin.roles.index') },
    { label: 'Matriz de permissões' },
]);
</script>

<template>
    <Head title="Matriz de permissões" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader title="Matriz de permissões" subtitle="Consulte e gerencie as permissões associadas aos papéis." />
            </div>

            <AdminSectionNav />

            <!-- Nenhum papel cadastrado -->
            <Card v-if="roles.length === 0" flush>
                <EmptyState icon="users" title="Nenhum papel cadastrado" description="Cadastre um papel para associar permissões a ele.">
                    <Button v-if="route().has('admin.roles.create')" :href="route('admin.roles.create')">Cadastrar papel</Button>
                </EmptyState>
            </Card>

            <template v-else>
                <!-- Seleção do papel -->
                <Card>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <label for="matrix-role" class="text-sm font-medium text-neutral-700">Papel</label>
                            <Select
                                id="matrix-role"
                                class="mt-1"
                                :model-value="roleChoice"
                                placeholder="Selecione um papel"
                                :disabled="form.processing || loadingRole"
                                @update:model-value="chooseRole"
                            >
                                <option v-for="role in roles" :key="role.id" :value="role.id">
                                    {{ role.name }}{{ role.is_system ? ' (sistema)' : '' }} —
                                    {{ count(role.permissions_count, 'permissão', 'permissões') }}
                                </option>
                            </Select>
                        </div>
                        <p v-if="loadingRole" class="inline-flex items-center gap-2 text-sm text-neutral-600" role="status" aria-live="polite">
                            <Spinner /> Carregando permissões…
                        </p>
                    </div>
                </Card>

                <!-- Nenhum papel selecionado: nada é escolhido automaticamente -->
                <Card v-if="!selected" flush>
                    <EmptyState
                        icon="shield-check"
                        title="Selecione um papel"
                        description="Escolha um papel acima para consultar e gerenciar as permissões associadas a ele."
                    />
                </Card>

                <form
                    v-else
                    class="space-y-6 transition-opacity duration-150"
                    :class="loadingRole && 'opacity-60'"
                    :aria-busy="loadingRole || form.processing"
                    novalidate
                    @submit.prevent="submit"
                >
                    <!-- Papel selecionado -->
                    <Card>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="break-words text-base font-medium text-neutral-900">{{ selected.name }}</h2>
                                    <Badge v-if="selected.is_system" tone="neutral">Sistema</Badge>
                                </div>
                                <p class="break-all font-mono text-xs text-neutral-500">{{ selected.slug }}</p>
                                <p class="mt-1 text-xs text-neutral-500">
                                    {{ count(persisted.size, 'permissão associada', 'permissões associadas') }} de
                                    {{ totalPermissions }} ·
                                    {{ count(selected.users_count, 'usuário vinculado', 'usuários vinculados') }}
                                </p>
                            </div>
                            <Badge v-if="isDirty" tone="primary" class="self-start sm:self-center">
                                {{ count(changedCount, 'alteração pendente', 'alterações pendentes') }}
                            </Badge>
                        </div>
                    </Card>

                    <!-- Mensagens -->
                    <div id="matrix-feedback" tabindex="-1" class="space-y-3 focus:outline-none">
                        <Alert v-if="successMessage" variant="success">{{ successMessage }}</Alert>
                        <Alert v-if="noChanges || infoMessage" variant="info">{{ infoMessage ?? 'Nenhuma alteração para salvar.' }}</Alert>
                        <Alert v-if="readOnlyMessage" variant="warning" title="Somente consulta">{{ readOnlyMessage }}</Alert>
                        <Alert v-if="errors.conflict" variant="danger" title="As permissões foram alteradas">
                            <p>{{ errors.conflict }}</p>
                            <Button variant="secondary" size="sm" class="mt-3" @click="reloadCurrent">Recarregar dados atuais</Button>
                        </Alert>
                        <Alert v-if="permissionsError" variant="danger" title="Não foi possível salvar">{{ permissionsError }}</Alert>
                        <Alert v-if="errors.version" variant="danger" title="Não foi possível salvar">{{ errors.version }}</Alert>
                        <Alert v-if="unexpectedError" variant="danger" title="Não foi possível salvar as permissões">
                            Ocorreu uma falha inesperada e nada foi gravado. Verifique sua conexão e tente novamente.
                        </Alert>
                        <Alert v-if="selected.permissions.length === 0 && !isDirty" variant="info">
                            Este papel não tem permissões associadas. Usuários vinculados a ele não acessam nenhuma operação protegida.
                        </Alert>
                    </div>

                    <!-- Catálogo vazio -->
                    <Card v-if="catalog.length === 0" flush>
                        <EmptyState icon="shield-check" title="Nenhuma permissão cadastrada" description="O catálogo de permissões está vazio." />
                    </Card>

                    <template v-else>
                        <!-- Pesquisa e filtros, aplicados sobre o catálogo completo -->
                        <Card>
                            <div class="space-y-4">
                                <div>
                                    <label for="matrix-search" class="text-sm font-medium text-neutral-700">Pesquisar permissões</label>
                                    <div class="mt-1">
                                        <SearchInput id="matrix-search" v-model="search" placeholder="Nome ou identificador..." maxlength="100" />
                                    </div>
                                </div>
                                <Tabs :model-value="view" :tabs="viewTabs" @update:model-value="setView" />
                            </div>
                        </Card>

                        <Card v-if="visibleGroups.length === 0" flush>
                            <EmptyState icon="search" title="Nenhuma permissão encontrada" description="Nenhuma permissão corresponde à pesquisa ou ao filtro.">
                                <Button variant="secondary" @click="clearSearch">Limpar pesquisa</Button>
                            </EmptyState>
                        </Card>

                        <!-- Permissões por módulo -->
                        <fieldset
                            v-for="group in visibleGroups"
                            :key="group.key"
                            class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm"
                        >
                            <legend class="sr-only">{{ group.label }}</legend>
                            <div class="border-b border-neutral-200 bg-neutral-100 px-4 py-2 text-xs font-semibold uppercase text-neutral-600" aria-hidden="true">
                                {{ group.label }}
                            </div>
                            <ul class="divide-y divide-neutral-200">
                                <li
                                    v-for="permission in group.permissions"
                                    :key="permission.slug"
                                    class="flex items-start gap-3 px-4 py-3"
                                    :class="{
                                        'bg-primary-50': changeState(permission.slug) === 'added',
                                        'bg-danger-bg/40': changeState(permission.slug) === 'removed',
                                    }"
                                >
                                    <Checkbox
                                        :id="`perm-${permission.slug}`"
                                        class="mt-0.5 h-5 w-5 shrink-0 disabled:cursor-not-allowed disabled:opacity-50"
                                        :checked="pending.has(permission.slug)"
                                        :disabled="!isEditable(permission) || form.processing"
                                        :aria-describedby="`perm-${permission.slug}-info`"
                                        @update:checked="(value: boolean) => toggle(permission.slug, value)"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <label :for="`perm-${permission.slug}`" class="text-sm font-medium text-neutral-900">
                                            {{ permission.name }}
                                        </label>
                                        <div :id="`perm-${permission.slug}-info`" class="mt-0.5 space-y-1">
                                            <p class="break-all font-mono text-xs text-neutral-500">{{ permission.slug }}</p>
                                            <p v-if="permission.description" class="text-xs text-neutral-600">{{ permission.description }}</p>
                                            <p v-if="canManage && !permission.grantable" class="text-xs text-neutral-500">
                                                Permissão administrativa que você não possui: não pode ser concedida nem retirada por você.
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-col items-end gap-1">
                                        <Badge v-if="permission.administrative" tone="warning">Administrativa</Badge>
                                        <Badge v-if="changeState(permission.slug) === 'added'" tone="primary">Será adicionada</Badge>
                                        <Badge v-if="changeState(permission.slug) === 'removed'" tone="danger">Será removida</Badge>
                                    </div>
                                </li>
                            </ul>
                        </fieldset>
                    </template>

                    <!-- Ações -->
                    <div
                        v-if="canManage"
                        class="sticky bottom-0 -mx-4 flex flex-col-reverse gap-3 border-t border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:flex-row sm:items-center sm:justify-end sm:rounded-lg sm:border"
                    >
                        <p class="text-sm text-neutral-600 sm:mr-auto" aria-live="polite">
                            {{ isDirty ? `${added.length} a adicionar · ${removed.length} a remover` : 'Nenhuma alteração pendente.' }}
                        </p>
                        <Button variant="ghost" class="w-full sm:w-auto" :disabled="!isDirty || form.processing" @click="restore">
                            Restaurar estado salvo
                        </Button>
                        <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="cancel">Cancelar</Button>
                        <Button type="submit" class="w-full sm:w-auto" :loading="form.processing" :disabled="!isDirty">
                            {{ form.processing ? 'Salvando…' : 'Salvar alterações' }}
                        </Button>
                    </div>
                </form>
            </template>
        </div>

        <ConfirmModal
            :show="leaveOpen"
            title="Descartar as alterações?"
            message="As alterações nas permissões ainda não foram salvas e serão perdidas."
            confirm-label="Descartar e sair"
            cancel-label="Continuar editando"
            variant="danger"
            @close="stay"
            @confirm="confirmLeave"
        />
    </AuthenticatedLayout>
</template>
