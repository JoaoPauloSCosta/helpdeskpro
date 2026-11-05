<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import ChatAvailabilityBadge from '@/Components/ChatAvailabilityBadge.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime } from '@/lib/ticket';
import type { AgentState } from '@/lib/chat';

/**
 * Equipe do chat (CHAT-06, RF-36). A capacidade é gravada pelo servidor, que valida o limite, audita a
 * mudança e nunca encerra sessões em andamento: com limite abaixo das ativas, o técnico só fica ocupado.
 */
interface AgentRow {
    id: number;
    name: string;
    email: string;
    availability: AgentState['availability'];
    active_sessions: number;
    max_sessions: number;
    last_seen_at: string | null;
}

const props = defineProps<{ agents: AgentRow[]; limits: { max: number } }>();

const drafts = ref<Record<number, string>>(Object.fromEntries(props.agents.map((a) => [a.id, String(a.max_sessions)])));
const saving = ref<number | null>(null);
const errors = ref<Record<number, string>>({});

const save = (agent: AgentRow) => {
    if (saving.value !== null) return;

    saving.value = agent.id;
    errors.value = {};

    router.patch(
        route('chat.admin.agents.update', { user: agent.id }),
        { max_sessions: drafts.value[agent.id] },
        {
            preserveScroll: true,
            onError: (errs) => (errors.value = { [agent.id]: errs.max_sessions ?? 'Não foi possível salvar.' }),
            onFinish: () => (saving.value = null),
        },
    );
};
</script>

<template>
    <Head title="Equipe do chat" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader title="Equipe do chat" subtitle="Capacidade de atendimentos simultâneos de cada técnico. Reduzir o limite não encerra atendimentos em andamento." />

            <EmptyState v-if="!agents.length" title="Nenhum técnico atende pelo chat" description="Técnicos com a permissão de atender pelo chat aparecem aqui." icon="chat" />

            <DataTable v-else>
                <template #head>
                    <tr>
                        <th scope="col">Técnico</th>
                        <th scope="col">Situação</th>
                        <th scope="col">Ativos</th>
                        <th scope="col">Último sinal</th>
                        <th scope="col">Limite</th>
                    </tr>
                </template>
                <tr v-for="agent in agents" :key="agent.id">
                    <td>
                        <p class="font-medium text-neutral-900">{{ agent.name }}</p>
                        <p class="text-xs text-neutral-600">{{ agent.email }}</p>
                    </td>
                    <td><ChatAvailabilityBadge :availability="agent.availability" /></td>
                    <td class="tabular-nums">{{ agent.active_sessions }}</td>
                    <td class="text-neutral-600">{{ agent.last_seen_at ? formatDateTime(agent.last_seen_at) : '—' }}</td>
                    <td>
                        <form class="flex items-start gap-2" novalidate @submit.prevent="save(agent)">
                            <div>
                                <TextInput
                                    v-model="drafts[agent.id]"
                                    type="number"
                                    min="1"
                                    :max="limits.max"
                                    class="w-20"
                                    :aria-label="`Limite de atendimentos simultâneos de ${agent.name}`"
                                    :invalid="!!errors[agent.id]"
                                />
                                <p v-if="errors[agent.id]" class="mt-1 max-w-48 text-xs text-danger-text" role="alert">{{ errors[agent.id] }}</p>
                            </div>
                            <Button type="submit" size="sm" variant="secondary" :loading="saving === agent.id" :disabled="drafts[agent.id] === String(agent.max_sessions)">
                                Salvar
                            </Button>
                        </form>
                    </td>
                </tr>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
