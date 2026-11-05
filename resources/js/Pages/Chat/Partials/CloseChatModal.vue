<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, ref, watch } from 'vue';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import RadioField from '@/Components/RadioField.vue';
import Select from '@/Components/Select.vue';
import Textarea from '@/Components/Textarea.vue';
import TextInput from '@/Components/TextInput.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import { useToast } from '@/composables/useToast';
import type { ChatClosureForm, ChatSessionState } from '@/lib/chat';

/**
 * Encerramento do atendimento (CHAT-05, RF-34). Gera o chamado no servidor; técnico, horários e status
 * são definidos por ele. O envio é protegido contra clique duplo e, se repetido, o servidor devolve o mesmo
 * chamado (idempotente). Os erros de validação vêm campo a campo.
 */
const props = defineProps<{
    show: boolean;
    session: Pick<ChatSessionState, 'id' | 'subject'>;
    form: ChatClosureForm;
}>();

const emit = defineEmits<{ close: []; ended: [session: ChatSessionState] }>();

const toast = useToast();

const outcome = ref<'resolved' | 'unresolved' | ''>('');
const title = ref('');
const categoryId = ref('');
const summary = ref('');
const note = ref('');
const errors = ref<Record<string, string>>({});
const processing = ref(false);
/** Preenchido depois do sucesso: a tela passa a mostrar o chamado gerado. */
const result = ref<ChatSessionState | null>(null);

// Cada abertura começa do zero, com o título sugerido pelo assunto informado pelo solicitante (D-C5).
watch(
    () => props.show,
    (open) => {
        if (!open) return;
        outcome.value = '';
        title.value = props.session.subject;
        categoryId.value = '';
        summary.value = '';
        note.value = '';
        errors.value = {};
        result.value = null;
    },
);

const dirty = computed(() => summary.value.trim() !== '' || note.value.trim() !== '' || outcome.value !== '');

const submit = async () => {
    if (processing.value) return;

    errors.value = {};
    processing.value = true;

    try {
        const { data } = await axios.post<ChatSessionState>(route('chat.sessions.closure.store', { session: props.session.id }), {
            outcome: outcome.value,
            title: title.value,
            category_id: categoryId.value,
            summary: outcome.value === 'resolved' ? summary.value : null,
            note: outcome.value === 'unresolved' ? note.value : null,
        });

        result.value = data;
        emit('ended', data);
    } catch (error) {
        if (!isAxiosError(error) || !error.response) return void toast.unexpected();

        const { status, data } = error.response;

        if (status === 422) {
            errors.value = Object.fromEntries(Object.entries<string[]>(data.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
            toast.formErrors();
        } else if (status === 409) {
            toast.info(data?.message ?? 'Este atendimento já não está em andamento.');
            emit('close');
        } else if (status === 429) toast.warning('Muitas tentativas seguidas. Aguarde um instante e tente de novo.');
        else toast.unexpected();
    } finally {
        processing.value = false;
    }
};

const close = () => {
    if (!processing.value) emit('close');
};
</script>

<template>
    <!-- Depois de preenchido, Esc e clique fora não descartam o que foi digitado; "Cancelar" continua disponível -->
    <Modal :show="show" max-width="lg" :closeable="!processing && !dirty" @close="close">
        <!-- Sucesso: o chamado gerado -->
        <div v-if="result" class="space-y-4 p-4 sm:p-6">
            <h2 class="text-lg font-semibold text-neutral-900">Atendimento encerrado</h2>
            <Alert variant="success" title="Chamado gerado">
                O atendimento ficou registrado no chamado
                <TicketNumber v-if="result.ticket_number" :number="result.ticket_number" />.
                {{ result.outcome?.value === 'resolved' ? 'O chamado foi marcado como resolvido.' : 'O chamado segue em atendimento pelo fluxo normal.' }}
            </Alert>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" class="w-full sm:w-auto" @click="emit('close')">Fechar</Button>
                <Button v-if="result.ticket_number" class="w-full sm:w-auto" :href="route('tickets.show', { ticket: result.ticket_number })">Ver o chamado</Button>
            </div>
        </div>

        <form v-else id="close-chat-form" class="flex max-h-[90vh] flex-col" novalidate @submit.prevent="submit">
            <div class="space-y-4 overflow-y-auto p-4 sm:p-6">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Encerrar atendimento</h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        Ao encerrar, o sistema gera um chamado com este atendimento. O solicitante recebe o número dele. A conversa fica anexada ao chamado.
                    </p>
                </div>

                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium text-neutral-700">O problema foi resolvido? <span class="text-danger-text" aria-hidden="true">*</span></legend>
                    <RadioField v-model="outcome" value="resolved" label="Resolvido" description="O chamado nasce como Resolvido e exige o resumo da solução." />
                    <RadioField v-model="outcome" value="unresolved" label="Não resolvido" description="O chamado nasce Em atendimento e segue pelo fluxo normal." />
                    <p v-if="errors.outcome" class="text-sm text-danger-text" role="alert">{{ errors.outcome }}</p>
                </fieldset>

                <FormField label="Título do chamado" required :error="errors.title" v-slot="{ id, describedby, invalid }">
                    <TextInput :id="id" v-model="title" :maxlength="form.limits.title" :aria-describedby="describedby" :invalid="invalid" autocomplete="off" />
                </FormField>

                <FormField label="Categoria" required :error="errors.category_id" v-slot="{ id, describedby, invalid }">
                    <Select :id="id" v-model="categoryId" placeholder="Selecione uma categoria" :aria-describedby="describedby" :invalid="invalid">
                        <option v-for="o in form.categories" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </Select>
                </FormField>

                <FormField
                    v-if="outcome === 'resolved'"
                    label="Resumo da solução"
                    required
                    :error="errors.summary"
                    help="Descreva o problema, a solução aplicada e o resultado. Fica visível no chamado, inclusive para o solicitante."
                    v-slot="{ id, describedby, invalid }"
                >
                    <Textarea :id="id" v-model="summary" :rows="5" :maxlength="form.limits.summary" :aria-describedby="describedby" :invalid="invalid" />
                </FormField>

                <FormField
                    v-else-if="outcome === 'unresolved'"
                    label="Observação para continuidade"
                    :error="errors.note"
                    help="Opcional. O que já foi tentado e o que falta, para quem continuar o atendimento."
                    v-slot="{ id, describedby, invalid }"
                >
                    <Textarea :id="id" v-model="note" :rows="4" :maxlength="form.limits.note" :aria-describedby="describedby" :invalid="invalid" />
                </FormField>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                <Button variant="secondary" class="w-full sm:w-auto" :disabled="processing" @click="close">Cancelar</Button>
                <Button type="submit" class="w-full sm:w-auto" :loading="processing" :disabled="!outcome || !title.trim() || !categoryId">
                    {{ processing ? 'Encerrando…' : 'Encerrar atendimento' }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
