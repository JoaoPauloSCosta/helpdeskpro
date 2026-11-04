<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Select from '@/Components/Select.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime } from '@/lib/ticket';
import type { Tone } from '@/lib/ui';

/** Histórico de atendimentos (CHAT-07). O servidor limita a lista ao que o usuário pode ver. */
interface Row {
    id: number;
    subject: string;
    requester: string;
    technician: string | null;
    status: { value: string; label: string };
    outcome: string | null;
    ticket_number: string | null;
    created_at: string;
    ended_at: string | null;
}

const props = defineProps<{
    sessions: Row[];
    meta: { current_page: number; per_page: number; total: number };
    filters: { status: string | null; q: string };
    statuses: { value: string; label: string }[];
    canSearch: boolean;
}>();

const status = ref(props.filters.status ?? '');
const q = ref(props.filters.q);

const tone: Record<string, Tone> = { waiting: 'info', active: 'primary', ended: 'success', cancelled: 'neutral' };

const go = (page = 1) =>
    router.get(route('chat.history.index'), { status: status.value || undefined, q: q.value || undefined, page: page > 1 ? page : undefined }, { preserveScroll: true, preserveState: true });
</script>

<template>
    <Head title="Histórico do chat" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader
                :title="canSearch ? 'Histórico de atendimentos' : 'Meus atendimentos'"
                subtitle="Atendimentos via chat já realizados, com a conversa e o chamado gerado."
            />

            <form class="flex flex-col gap-3 sm:flex-row" role="search" @submit.prevent="go()">
                <div v-if="canSearch" class="sm:w-80">
                    <SearchInput v-model="q" aria-label="Buscar por assunto ou solicitante" placeholder="Assunto ou solicitante" />
                </div>
                <Select v-model="status" aria-label="Filtrar por situação" class="sm:w-56" @change="go()">
                    <option value="">Todas as situações</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </Select>
                <Button type="submit" variant="secondary">Filtrar</Button>
            </form>

            <EmptyState v-if="!sessions.length" title="Nenhum atendimento encontrado" description="Os atendimentos via chat aparecem aqui." icon="chat" />

            <DataTable v-else>
                <template #head>
                    <tr>
                        <th scope="col">Nº</th>
                        <th scope="col">Assunto</th>
                        <th scope="col">Solicitante</th>
                        <th scope="col">Técnico</th>
                        <th scope="col">Situação</th>
                        <th scope="col">Pedido em</th>
                        <th scope="col">Chamado</th>
                    </tr>
                </template>
                <tr v-for="row in sessions" :key="row.id">
                    <td class="tabular-nums">
                        <Link :href="route('chat.history.show', { session: row.id })" class="font-medium text-primary-600 hover:underline">{{ row.id }}</Link>
                    </td>
                    <td class="max-w-xs truncate">{{ row.subject }}</td>
                    <td>{{ row.requester }}</td>
                    <td>{{ row.technician ?? '—' }}</td>
                    <td>
                        <Badge :tone="tone[row.status.value] ?? 'neutral'">{{ row.status.label }}</Badge>
                        <span v-if="row.outcome" class="ms-1 text-xs text-neutral-600">{{ row.outcome }}</span>
                    </td>
                    <td class="whitespace-nowrap text-neutral-600">{{ formatDateTime(row.created_at) }}</td>
                    <td>
                        <Link v-if="row.ticket_number" :href="route('tickets.show', { ticket: row.ticket_number })" class="font-mono text-primary-600 hover:underline">{{ row.ticket_number }}</Link>
                        <span v-else class="text-neutral-500">—</span>
                    </td>
                </tr>
                <template #footer>
                    <Pagination :page="meta.current_page" :per-page="meta.per_page" :total="meta.total" @update:page="go" />
                </template>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
