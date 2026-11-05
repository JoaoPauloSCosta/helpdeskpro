<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ChatConnectionStatus from '@/Components/ChatConnectionStatus.vue';
import ChatConversation from '@/Components/ChatConversation.vue';
import ChatQueuePosition from '@/Components/ChatQueuePosition.vue';
import ChatSoundToggle from '@/Components/ChatSoundToggle.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TextInput from '@/Components/TextInput.vue';
import { useRequesterChat } from '@/composables/useRequesterChat';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import type { ChatSessionState } from '@/lib/chat';

const props = defineProps<{ session: ChatSessionState | null }>();

// A lógica é a mesma do botão flutuante (useRequesterChat); aqui a conversa está sempre à vista.
const {
    config, userId, sound, live, session, subject, errors, requesting, cancelling, confirmCancel, unavailable,
    status, conversation, closed, requestChat, cancel, newRequest,
} = useRequesterChat(props.session);
</script>

<template>
    <Head title="Atendimento via chat" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader
                title="Atendimento via Chat"
                subtitle="Converse em tempo real com um técnico. Para algo que pode esperar, abra um chamado."
            />

            <!-- CHAT-01: solicitar atendimento -->
            <Card v-if="!session" title="Solicitar atendimento">
                <form novalidate class="space-y-4" @submit.prevent="requestChat">
                    <FormField
                        label="Sobre o que você precisa de ajuda?"
                        :error="errors.subject"
                        :help="`Em poucas palavras. Este assunto fica registrado no chamado gerado ao final do atendimento.`"
                        required
                        v-slot="{ id, describedby, invalid }"
                    >
                        <TextInput
                            :id="id"
                            v-model="subject"
                            :maxlength="config.subjectMaxLength"
                            :aria-describedby="describedby"
                            :invalid="invalid"
                            autocomplete="off"
                        />
                    </FormField>
                    <p v-if="errors.department" class="text-sm text-danger-text" role="alert">{{ errors.department }}</p>

                    <Alert v-if="unavailable" variant="warning" title="Nenhum técnico disponível">
                        {{ unavailable }}
                        <div class="mt-3">
                            <Button :href="route('tickets.create')">Abrir chamado tradicional</Button>
                        </div>
                    </Alert>

                    <div class="flex flex-wrap items-center gap-3">
                        <Button type="submit" :loading="requesting" :disabled="!subject.trim()">
                            <Icon v-if="!requesting" name="chat" :size="20" /> Solicitar atendimento
                        </Button>
                        <Button variant="ghost" :href="route('tickets.create')">Abrir chamado tradicional</Button>
                    </div>
                </form>
            </Card>

            <!-- CHAT-02: aguardando atendimento -->
            <Card v-else-if="status === 'waiting'" title="Aguardando atendimento">
                <div class="space-y-6">
                    <ChatQueuePosition v-if="session.queue" :position="session.queue.position" />
                    <p class="text-center text-sm text-neutral-600">
                        Assunto: <span class="font-medium text-neutral-900">{{ session.subject }}</span>
                    </p>

                    <Alert v-if="session.no_technician_on_duty" variant="warning" title="Nenhum técnico disponível no momento">
                        Sua solicitação continua na fila. Se preferir, abra um chamado e acompanhe o andamento por ele.
                        <div class="mt-3">
                            <Button variant="secondary" :href="route('tickets.create')">Abrir chamado tradicional</Button>
                        </div>
                    </Alert>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-4">
                        <ChatConnectionStatus :status="live.status.value" />
                        <Button variant="secondary" @click="confirmCancel = true">Cancelar solicitação</Button>
                    </div>
                    <ChatSoundToggle :model-value="sound.enabled.value" :saving="sound.saving.value" :blocked="sound.blocked.value" @update:model-value="sound.setEnabled" @test="sound.test" />
                </div>
            </Card>

            <!-- CHAT-03: conversa -->
            <template v-else-if="status === 'active' || status === 'ended'">
                <Alert v-if="status === 'ended'" variant="success" title="Atendimento encerrado">
                    <template v-if="session.ticket_number">
                        <template v-if="session.outcome">O técnico marcou o atendimento como <strong>{{ session.outcome.label.toLowerCase() }}</strong>. </template>
                        Registramos a conversa no chamado <strong class="font-mono">{{ session.ticket_number }}</strong>.
                    </template>
                    <template v-else>A conversa fica registrada no seu histórico de atendimentos.</template>
                    <div class="mt-3 flex gap-3">
                        <Button v-if="session.ticket_number" :href="route('tickets.show', { ticket: session.ticket_number })">Ver o chamado</Button>
                        <Button variant="secondary" @click="newRequest">Novo atendimento</Button>
                    </div>
                </Alert>

                <Card flush>
                    <template #header>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-neutral-900">Atendimento com {{ session.technician?.name ?? 'técnico' }}</h2>
                            <p class="truncate text-sm text-neutral-600">{{ session.subject }}</p>
                        </div>
                        <ChatConnectionStatus :status="live.status.value" />
                    </template>
                    <div class="h-[65vh] min-h-[26rem]">
                        <ChatConversation
                            :messages="conversation.messages.value"
                            :current-user-id="userId"
                            :closed="closed"
                            :loading="conversation.loading.value"
                            :max-length="config.messageMaxLength"
                            @send="conversation.send"
                            @retry="conversation.retry"
                            @discard="conversation.discard"
                        />
                    </div>
                    <template #footer>
                        <ChatSoundToggle :model-value="sound.enabled.value" :saving="sound.saving.value" :blocked="sound.blocked.value" @update:model-value="sound.setEnabled" @test="sound.test" />
                    </template>
                </Card>
            </template>

            <ConfirmModal
                :show="confirmCancel"
                title="Cancelar a solicitação?"
                message="Você sai da fila e perde a sua posição. Se precisar de ajuda depois, será preciso solicitar de novo."
                confirm-label="Cancelar solicitação"
                cancel-label="Continuar na fila"
                variant="danger"
                :processing="cancelling"
                @close="confirmCancel = false"
                @confirm="cancel"
            />
        </div>
    </AuthenticatedLayout>
</template>
