<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime, formatDuration } from '@/lib/ticket';

/** Indicadores do chat (CHAT-08). Tudo vem calculado do servidor; a tela só formata. */
interface TechnicianRow {
    id: number;
    name: string;
    attended: number;
    ended: number;
    resolved_rate: number | null;
    avg_first_response_seconds: number | null;
    avg_duration_seconds: number | null;
    active_now: number;
    max_sessions: number;
}

defineProps<{
    metrics: {
        days: number;
        from: string;
        generated_at: string;
        requested: number;
        ended: number;
        cancelled: number;
        resolved: number;
        unresolved: number;
        resolved_rate: number | null;
        cancelled_rate: number | null;
        avg_wait_seconds: number | null;
        avg_first_response_seconds: number | null;
        avg_duration_seconds: number | null;
        now: { waiting: number; active: number };
        technicians: TechnicianRow[];
    };
    periods: number[];
}>();

const duration = (seconds: number | null) => (seconds === null ? '—' : formatDuration(seconds));
const percent = (value: number | null) => (value === null ? '—' : `${value.toLocaleString('pt-BR')}%`);
const choose = (days: number) => router.get(route('chat.metrics.index'), { days }, { preserveScroll: true });
</script>

<template>
    <Head title="Indicadores do chat" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader title="Indicadores do chat" :subtitle="`Atendimentos pedidos desde ${formatDateTime(metrics.from)}. Atualizado em ${formatDateTime(metrics.generated_at)}.`">
                <div class="flex gap-2" role="group" aria-label="Período">
                    <Button
                        v-for="days in periods"
                        :key="days"
                        size="sm"
                        :variant="days === metrics.days ? 'primary' : 'secondary'"
                        :aria-pressed="days === metrics.days"
                        @click="choose(days)"
                    >
                        {{ days }} dias
                    </Button>
                </div>
            </PageHeader>

            <section aria-label="Agora" class="grid gap-4 sm:grid-cols-2">
                <StatCard label="Aguardando na fila agora" :value="metrics.now.waiting" icon="chat" />
                <StatCard label="Em atendimento agora" :value="metrics.now.active" icon="chat" />
            </section>

            <section aria-label="Período" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Atendimentos pedidos" :value="metrics.requested" :hint="`${metrics.cancelled} cancelados (${percent(metrics.cancelled_rate)})`" />
                <StatCard label="Encerrados" :value="metrics.ended" :hint="`${metrics.resolved} resolvidos · ${metrics.unresolved} não resolvidos`" />
                <StatCard label="Resolvidos" :value="percent(metrics.resolved_rate)" hint="Dos atendimentos encerrados" />
                <StatCard label="Espera média na fila" :value="duration(metrics.avg_wait_seconds)" hint="Do pedido ao início do atendimento" />
                <StatCard label="Primeira resposta média" :value="duration(metrics.avg_first_response_seconds)" hint="Do início à primeira mensagem do técnico" />
                <StatCard label="Duração média" :value="duration(metrics.avg_duration_seconds)" hint="Do início ao encerramento" />
            </section>

            <section aria-labelledby="by-technician" class="space-y-3">
                <h2 id="by-technician" class="text-lg font-semibold text-neutral-900">Por técnico</h2>
                <EmptyState v-if="!metrics.technicians.length" title="Sem atendimentos no período" description="Os indicadores por técnico aparecem quando houver atendimentos." icon="chat" />
                <DataTable v-else>
                    <template #head>
                        <tr>
                            <th scope="col">Técnico</th>
                            <th scope="col">Atendidos</th>
                            <th scope="col">Encerrados</th>
                            <th scope="col">Resolvidos</th>
                            <th scope="col">1ª resposta</th>
                            <th scope="col">Duração</th>
                            <th scope="col">Agora</th>
                        </tr>
                    </template>
                    <tr v-for="tech in metrics.technicians" :key="tech.id">
                        <td class="font-medium text-neutral-900">{{ tech.name }}</td>
                        <td class="tabular-nums">{{ tech.attended }}</td>
                        <td class="tabular-nums">{{ tech.ended }}</td>
                        <td class="tabular-nums">{{ percent(tech.resolved_rate) }}</td>
                        <td>{{ duration(tech.avg_first_response_seconds) }}</td>
                        <td>{{ duration(tech.avg_duration_seconds) }}</td>
                        <td class="tabular-nums">{{ tech.active_now }} / {{ tech.max_sessions }}</td>
                    </tr>
                </DataTable>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
