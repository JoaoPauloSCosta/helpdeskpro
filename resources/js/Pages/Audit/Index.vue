<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
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
import Select from '@/Components/Select.vue';
import SortableHeader from '@/Components/SortableHeader.vue';
import Spinner from '@/Components/Spinner.vue';
import TextInput from '@/Components/TextInput.vue';
import { useListing, type Direction } from '@/composables/useListing';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    auditFilterKeys,
    formatAuditDateTime,
    formatFilterDate,
    type AuditDetail,
    type AuditFilterKey,
    type AuditFilterOptions,
    type AuditFilters,
    type AuditListItem,
    type AuditValue,
} from '@/lib/audit';
import type { Paginator } from '@/types';

const props = defineProps<{
    logs: Paginator<AuditListItem>;
    filters: AuditFilters;
    sort: { key: 'created_at'; direction: Direction };
    options: AuditFilterOptions;
    rangeLimit: number;
    hasAnyLogs: boolean;
    detail: AuditDetail | null;
    selected: number | null;
}>();

const defaultSort = { key: 'created_at', direction: 'desc' } as const;

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
} = useListing({
    routeName: 'audit.index',
    filterKeys: auditFilterKeys,
    filters: () => props.filters,
    sort: () => props.sort,
    defaultSort,
});

/** Seletores aplicam o filtro ao serem escolhidos; os campos de texto e de data, ao enviar o formulário. */
const applySelect = (key: 'event' | 'entity', value: string | number | null) => {
    form[key] = String(value ?? '');
    submitFilters();
};

/* ---------- Filtros ativos ---------- */

const chipLabel = (key: AuditFilterKey, value: string): string => {
    switch (key) {
        case 'q':
            return `Autor: ${value}`;
        case 'event':
            return `Evento: ${props.options.events.find((o) => o.value === value)?.label ?? value}`;
        case 'entity':
            return `Entidade: ${props.options.entities.find((o) => o.value === value)?.label ?? value}`;
        case 'entity_id':
            return `Identificador: ${value}`;
        case 'from':
            return `De: ${formatFilterDate(value)}`;
        case 'to':
            return `Até: ${formatFilterDate(value)}`;
    }
};

const activeChips = computed(() =>
    auditFilterKeys
        .filter((key) => props.filters[key])
        .map((key) => ({ key, label: chipLabel(key, props.filters[key]!) })),
);

/* ---------- Detalhes ---------- */

const loadingDetail = ref(false);

/** Parâmetros da listagem atual, para abrir e fechar o painel sem perder filtros nem página. */
const listingParams = () => {
    const isDefaultSort = props.sort.direction === defaultSort.direction;

    return {
        ...props.filters,
        ...(isDefaultSort ? {} : { sort: props.sort.key, direction: props.sort.direction }),
        ...(props.logs.current_page > 1 ? { page: props.logs.current_page } : {}),
    };
};

const visitDetail = (selected?: number) =>
    router.get(route('audit.index'), { ...listingParams(), ...(selected ? { selected } : {}) }, {
        preserveState: true,
        preserveScroll: true,
        only: ['detail', 'selected'],
        onStart: () => (loadingDetail.value = true),
        onFinish: () => (loadingDetail.value = false),
    });

const openDetail = (log: AuditListItem) => visitDetail(log.id);
const closeDetail = () => visitDetail();
const detailLabel = (log: AuditListItem) => `Ver detalhes do evento ${log.id}: ${log.event.label}, ${entityText(log)}`;

/* ---------- Resultados ---------- */

const rows = computed(() => props.logs.data);
const isEmpty = computed(() => rows.value.length === 0);
const formatCount = (value: number) => value.toLocaleString('pt-BR');

const entityText = (log: AuditListItem) =>
    `${log.entity.type}${log.entity.name ? ` ${log.entity.name}` : ''} (#${log.entity.id})`;

const valueText = (value: AuditValue) => (Array.isArray(value) ? value.join(', ') : value);

const breadcrumbs = [{ label: 'Início', href: route('dashboard') }, { label: 'Auditoria' }];
</script>

<template>
    <Head title="Consulta de auditoria" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="space-y-2">
                <Breadcrumbs :items="breadcrumbs" />
                <PageHeader
                    title="Consulta de auditoria"
                    subtitle="Eventos das alterações administrativas: usuários, departamentos, categorias, papéis, permissões e políticas de SLA."
                />
            </div>

            <Alert variant="info">
                Somente consulta. Os eventos dos chamados (abertura, status, comentários...) ficam no histórico de cada chamado.
            </Alert>

            <!-- Filtros -->
            <Card>
                <form aria-label="Filtros de auditoria" @submit.prevent="submitFilters">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <FormField label="Autor" :error="serverErrors.q" v-slot="{ id, describedby, invalid }">
                            <TextInput
                                :id="id"
                                v-model="form.q"
                                placeholder="Nome ou e-mail de quem fez a alteração"
                                maxlength="100"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                            />
                        </FormField>
                        <FormField label="Evento" :error="serverErrors.event" v-slot="{ id, describedby, invalid }">
                            <Select
                                :id="id"
                                :model-value="form.event"
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                :disabled="processing"
                                @update:model-value="applySelect('event', $event)"
                            >
                                <option value="">Todos</option>
                                <option v-for="o in options.events" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <div class="grid grid-cols-2 gap-3">
                            <FormField label="Entidade" :error="serverErrors.entity" v-slot="{ id, describedby, invalid }">
                                <Select
                                    :id="id"
                                    :model-value="form.entity"
                                    :aria-describedby="describedby"
                                    :invalid="invalid"
                                    :disabled="processing"
                                    @update:model-value="applySelect('entity', $event)"
                                >
                                    <option value="">Todas</option>
                                    <option v-for="o in options.entities" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </Select>
                            </FormField>
                            <FormField label="Identificador" :error="serverErrors.entity_id" v-slot="{ id, describedby, invalid }">
                                <TextInput
                                    :id="id"
                                    v-model="form.entity_id"
                                    type="number"
                                    min="1"
                                    inputmode="numeric"
                                    placeholder="Ex.: 12"
                                    :aria-describedby="describedby"
                                    :invalid="invalid"
                                />
                            </FormField>
                        </div>
                        <FormField
                            label="Data inicial (UTC)"
                            :error="serverErrors.from"
                            :help="`Período de até ${rangeLimit} dias.`"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <TextInput :id="id" v-model="form.from" type="date" :aria-describedby="describedby" :invalid="invalid" />
                        </FormField>
                        <FormField label="Data final (UTC)" :error="serverErrors.to" v-slot="{ id, describedby, invalid }">
                            <TextInput :id="id" v-model="form.to" type="date" :aria-describedby="describedby" :invalid="invalid" />
                        </FormField>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <!-- Filtros ativos: cada um pode ser removido individualmente -->
                        <div v-if="activeChips.length" class="min-w-0">
                            <p id="active-audit-filters-label" class="sr-only">Filtros ativos</p>
                            <ul class="flex flex-wrap gap-2" aria-labelledby="active-audit-filters-label">
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

                        <div class="flex shrink-0 flex-col-reverse gap-3 sm:flex-row sm:items-center">
                            <Button
                                variant="ghost"
                                :disabled="processing || (!hasAppliedFilters && formIsEmpty)"
                                @click="clearFilters"
                            >
                                Limpar filtros
                            </Button>
                            <Button type="submit" :loading="processing">
                                <Icon name="search" :size="16" /> Filtrar
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>

            <Alert v-if="loadError" variant="danger" title="Não foi possível carregar os eventos de auditoria">
                <p>Verifique sua conexão e tente novamente.</p>
                <Button variant="secondary" size="sm" class="mt-3" @click="retry">Tentar novamente</Button>
            </Alert>

            <!-- Resultados -->
            <section aria-labelledby="audit-results-title" :aria-busy="processing">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="audit-results-title" class="text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">{{ formatCount(logs.total) }}</span>
                        {{ logs.total === 1 ? 'evento encontrado' : 'eventos encontrados' }}
                    </h2>
                    <p v-if="processing" class="inline-flex items-center gap-2 text-sm text-neutral-600" role="status" aria-live="polite">
                        <Spinner /> Atualizando resultados…
                    </p>

                    <!-- Ordenação no celular, onde a tabela vira cartões -->
                    <Button
                        v-if="!isEmpty"
                        variant="secondary"
                        size="sm"
                        class="md:hidden"
                        :disabled="processing"
                        @click="sortBy('created_at')"
                    >
                        <Icon :name="sort.direction === 'desc' ? 'arrow-down' : 'arrow-up'" :size="16" />
                        {{ sort.direction === 'desc' ? 'Mais recentes primeiro' : 'Mais antigos primeiro' }}
                    </Button>
                </div>

                <div class="transition-opacity duration-150" :class="processing && 'opacity-60'">
                    <!-- Estados vazios -->
                    <Card v-if="isEmpty" flush>
                        <EmptyState
                            v-if="!hasAnyLogs"
                            icon="shield-check"
                            title="Nenhum evento de auditoria registrado"
                            description="Ainda não há alterações administrativas gravadas na trilha de auditoria."
                        />
                        <EmptyState
                            v-else-if="hasAppliedFilters"
                            icon="funnel"
                            title="Nenhum evento encontrado"
                            description="Nenhum evento corresponde aos filtros aplicados. Ajuste ou limpe os filtros para ver outros períodos e entidades."
                        >
                            <Button variant="secondary" :disabled="processing" @click="clearFilters">Limpar filtros</Button>
                        </EmptyState>
                        <!-- Página além da última, por exemplo depois de um link antigo -->
                        <EmptyState v-else icon="search" title="Nenhum evento encontrado" description="Esta página não tem resultados.">
                            <Button variant="secondary" :disabled="processing" @click="goToPage(1)">Ir para a primeira página</Button>
                        </EmptyState>
                    </Card>

                    <template v-else>
                        <!-- Tablet e desktop: tabela -->
                        <div class="hidden md:block">
                            <DataTable>
                                <template #head>
                                    <tr class="whitespace-nowrap">
                                        <SortableHeader
                                            label="Data e hora (UTC)"
                                            :direction="direction('created_at')"
                                            @sort="sortBy('created_at')"
                                        />
                                        <th scope="col">Evento</th>
                                        <th scope="col">Entidade</th>
                                        <th scope="col">Autor</th>
                                        <th scope="col"><span class="sr-only">Ações</span></th>
                                    </tr>
                                </template>

                                <tr v-for="log in rows" :key="log.id">
                                    <td class="whitespace-nowrap text-neutral-600">{{ formatAuditDateTime(log.created_at) }}</td>
                                    <td class="font-medium">{{ log.event.label }}</td>
                                    <td>
                                        <span class="text-neutral-500">{{ log.entity.type }}</span>
                                        <span v-if="log.entity.name" class="break-words"> · {{ log.entity.name }}</span>
                                        <span class="font-mono text-xs text-neutral-500"> #{{ log.entity.id }}</span>
                                    </td>
                                    <td class="text-neutral-600">{{ log.author ?? 'Sem usuário associado' }}</td>
                                    <td class="whitespace-nowrap text-right">
                                        <Button variant="ghost" size="sm" :aria-label="detailLabel(log)" @click="openDetail(log)">
                                            <Icon name="eye" :size="16" /> Detalhes
                                        </Button>
                                    </td>
                                </tr>

                                <template #footer>
                                    <Pagination :page="logs.current_page" :per-page="logs.per_page" :total="logs.total" @update:page="goToPage" />
                                </template>
                            </DataTable>
                        </div>

                        <!-- Celular: cartões compactos -->
                        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm md:hidden">
                            <ul class="divide-y divide-neutral-200">
                                <li v-for="log in rows" :key="log.id" class="space-y-2 p-4">
                                    <p class="text-xs text-neutral-500">{{ formatAuditDateTime(log.created_at) }} UTC</p>
                                    <p class="font-medium text-neutral-900">{{ log.event.label }}</p>
                                    <p class="break-words text-sm text-neutral-600">{{ entityText(log) }}</p>
                                    <p class="text-sm text-neutral-600">Por {{ log.author ?? 'sem usuário associado' }}</p>
                                    <Button variant="secondary" block class="!mt-3" :aria-label="detailLabel(log)" @click="openDetail(log)">
                                        <Icon name="eye" :size="16" /> Detalhes
                                    </Button>
                                </li>
                            </ul>
                            <Pagination :page="logs.current_page" :per-page="logs.per_page" :total="logs.total" @update:page="goToPage" />
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
                            <h2 class="break-words text-lg font-semibold text-neutral-900">{{ detail.event.label }}</h2>
                            <p class="mt-1 text-xs text-neutral-500">Evento #{{ detail.id }} · {{ formatAuditDateTime(detail.created_at) }} UTC</p>
                        </div>
                        <Badge v-if="!detail.entity.exists" tone="neutral" class="shrink-0">Cadastro indisponível</Badge>
                    </div>

                    <dl class="mt-6 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-neutral-700">Entidade</dt>
                            <dd class="mt-1 break-words text-neutral-600">{{ entityText(detail) }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-neutral-700">Autor</dt>
                            <dd class="mt-1 text-neutral-600">{{ detail.author ?? 'Sem usuário associado' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-neutral-700">Alterações registradas</dt>
                            <dd class="mt-2">
                                <ul v-if="detail.changes.length" class="space-y-3">
                                    <li v-for="change in detail.changes" :key="change.field" class="rounded-md border border-neutral-200 p-3">
                                        <p class="text-xs font-medium text-neutral-700">{{ change.field }}</p>
                                        <p class="mt-1 text-neutral-500">
                                            <span class="text-xs">Antes:</span>
                                            <span class="break-words"> {{ valueText(change.from) || '—' }}</span>
                                        </p>
                                        <p class="mt-1 text-neutral-900">
                                            <span class="text-xs text-neutral-500">Depois:</span>
                                            <span class="break-words"> {{ valueText(change.to) || '—' }}</span>
                                        </p>
                                    </li>
                                </ul>
                                <p v-else class="text-neutral-500">Este evento não tem valores adicionais para exibir.</p>
                                <p class="mt-2 text-xs text-neutral-500">
                                    Senhas, tokens e outros dados de acesso nunca são exibidos.
                                </p>
                            </dd>
                        </div>
                    </dl>
                </template>

                <template v-else>
                    <h2 class="text-lg font-semibold text-neutral-900">Evento não encontrado</h2>
                    <p class="mt-2 text-sm text-neutral-600">O evento solicitado não existe ou não está disponível.</p>
                </template>

                <div class="mt-6 flex justify-end">
                    <Button variant="secondary" :loading="loadingDetail" @click="closeDetail">Fechar</Button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
