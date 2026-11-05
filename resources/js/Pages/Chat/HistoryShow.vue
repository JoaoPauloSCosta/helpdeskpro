<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ChatConversation from '@/Components/ChatConversation.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useChatConversation } from '@/composables/useChatConversation';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import type { ChatSessionState } from '@/lib/chat';
import { formatDateTime } from '@/lib/ticket';
import type { PageProps } from '@/types';
import { onMounted, ref } from 'vue';

/** Detalhe de um atendimento (CHAT-07): dados, linha do tempo e conversa, somente leitura. */
const props = defineProps<{ session: ChatSessionState & { events: { label: string; author: string | null; at: string }[] } }>();

const page = usePage<PageProps>();
const userId = page.props.auth.user.id;
const sessionId = ref<number | null>(props.session.id);
const conversation = useChatConversation(sessionId, userId);
const open = computed(() => props.session.status.value === 'active');

// Só leitura: não marca como lida nem envia; sessão em andamento mostra o que existe até agora.
onMounted(() => void conversation.load());
</script>

<template>
    <Head :title="`Atendimento ${session.id}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader :title="`Atendimento nº ${session.id}`" :subtitle="session.subject">
                <Button variant="secondary" :href="route('chat.history.index')">Voltar</Button>
            </PageHeader>

            <div class="grid gap-6 lg:grid-cols-3">
                <Card title="Dados" class="lg:col-span-1">
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-neutral-500">Situação</dt>
                            <dd class="mt-0.5">
                                <Badge :tone="session.status.value === 'ended' ? 'success' : 'neutral'">{{ session.status.label }}</Badge>
                                <span v-if="session.outcome" class="ms-1 text-neutral-700">{{ session.outcome.label }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-neutral-500">Solicitante</dt>
                            <dd class="mt-0.5 text-neutral-900">{{ session.requester?.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-neutral-500">Técnico</dt>
                            <dd class="mt-0.5 text-neutral-900">{{ session.technician?.name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-neutral-500">Chamado gerado</dt>
                            <dd class="mt-0.5">
                                <Link v-if="session.ticket_number" :href="route('tickets.show', { ticket: session.ticket_number })" class="font-mono text-primary-600 hover:underline">{{ session.ticket_number }}</Link>
                                <span v-else class="text-neutral-500">—</span>
                            </dd>
                        </div>
                    </dl>

                    <ol class="mt-6 space-y-2 border-t border-neutral-200 pt-4 text-sm" aria-label="Linha do tempo do atendimento">
                        <li v-for="(event, i) in session.events" :key="i">
                            <span class="font-medium text-neutral-900">{{ event.label }}</span>
                            <span v-if="event.author" class="text-neutral-600"> · {{ event.author }}</span>
                            <p class="text-xs text-neutral-500">{{ formatDateTime(event.at) }}</p>
                        </li>
                    </ol>
                </Card>

                <Card flush class="lg:col-span-2">
                    <template #header>
                        <h2 class="text-lg font-semibold text-neutral-900">Conversa</h2>
                    </template>
                    <div class="h-[60vh] min-h-[22rem]">
                        <ChatConversation
                            :messages="conversation.messages.value"
                            :current-user-id="userId"
                            closed
                            :closed-message="open ? 'Atendimento em andamento. Esta tela é somente leitura.' : 'Conversa encerrada. Somente leitura.'"
                            :loading="conversation.loading.value"
                            :max-length="0"
                        />
                    </div>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
