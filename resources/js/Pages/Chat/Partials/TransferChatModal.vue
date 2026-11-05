<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { ref, watch } from 'vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import Select from '@/Components/Select.vue';
import { useToast } from '@/composables/useToast';

/**
 * Transferência do atendimento (CHAT-05, RF-35). A lista traz só técnicos de plantão e com vaga; o servidor
 * confere tudo de novo ao transferir, então a resposta 409 (alguém ocupou a vaga) aparece aqui, sem fechar.
 */
const props = defineProps<{ show: boolean; session: { id: number; subject: string } }>();
const emit = defineEmits<{ close: []; transferred: [technician: string] }>();

interface Target { id: number; name: string; active_sessions: number; max_sessions: number }

const toast = useToast();
const targets = ref<Target[]>([]);
const targetId = ref('');
const loading = ref(false);
const processing = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.show,
    async (open) => {
        if (!open) return;
        targetId.value = '';
        error.value = null;
        targets.value = [];
        loading.value = true;

        try {
            targets.value = (await axios.get<{ targets: Target[] }>(route('chat.sessions.transfer.targets', { session: props.session.id }))).data.targets;
        } catch {
            error.value = 'Não foi possível carregar os técnicos. Tente novamente.';
        } finally {
            loading.value = false;
        }
    },
);

const submit = async () => {
    if (processing.value || !targetId.value) return;
    processing.value = true;
    error.value = null;

    try {
        const { data } = await axios.post<{ technician: { name: string } | null }>(route('chat.sessions.transfer.store', { session: props.session.id }), { technician_id: Number(targetId.value) });
        toast.success(`Atendimento transferido para ${data.technician?.name ?? 'outro técnico'}.`);
        emit('transferred', data.technician?.name ?? '');
        emit('close');
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 409) error.value = e.response.data?.message ?? 'Não foi possível transferir.';
        else toast.unexpected();
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <Modal :show="show" max-width="md" :closeable="!processing" @close="emit('close')">
        <form class="space-y-4 p-4 sm:p-6" novalidate @submit.prevent="submit">
            <div>
                <h2 class="text-lg font-semibold text-neutral-900">Transferir atendimento</h2>
                <p class="mt-1 text-sm text-neutral-600">
                    O atendimento “{{ session.subject }}” passa direto para o técnico escolhido. Você deixa de ter acesso à conversa.
                </p>
            </div>

            <Alert v-if="error" variant="danger">{{ error }}</Alert>
            <Alert v-else-if="!loading && !targets.length" variant="warning" title="Nenhum técnico disponível">
                Não há outro técnico de plantão com vaga neste momento.
            </Alert>

            <FormField v-if="targets.length" label="Técnico" required v-slot="{ id, describedby }">
                <Select :id="id" v-model="targetId" placeholder="Selecione um técnico" :aria-describedby="describedby">
                    <option v-for="t in targets" :key="t.id" :value="t.id">{{ t.name }} ({{ t.active_sessions }}/{{ t.max_sessions }} atendimentos)</option>
                </Select>
            </FormField>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" class="w-full sm:w-auto" :disabled="processing" @click="emit('close')">Cancelar</Button>
                <Button type="submit" class="w-full sm:w-auto" :loading="processing" :disabled="!targetId">Transferir</Button>
            </div>
        </form>
    </Modal>
</template>
