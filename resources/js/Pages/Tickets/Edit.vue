<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import PriorityBadge from '@/Components/PriorityBadge.vue';
import Select from '@/Components/Select.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import type { Option, TicketPriority, TicketStatus } from '@/lib/ticket';

type Field = 'priority' | 'category_id' | 'department_id';

const props = defineProps<{
    ticket: { number: string; title: string; priority: TicketPriority; status: TicketStatus };
    values: Record<Field, string>;
    options: { priorities: Option[]; categories: Option[]; departments: Option[] };
    current: { category: string | null; department: string | null };
    can: { priority: boolean; category: boolean; department: boolean };
    version: string;
}>();

const page = usePage();

const form = useForm({ ...props.values, version: props.version });

/** Campo do formulário => permissão recebida do servidor. Só os permitidos são enviados. */
const allowed: Record<Field, boolean> = {
    priority: props.can.priority,
    category_id: props.can.category,
    department_id: props.can.department,
};

const otherError = (key: 'conflict' | 'version') => (form.errors as Record<string, string | undefined>)[key];
const formError = computed(() => otherError('version'));

const noChanges = ref(false);
const serverInfo = computed(() => page.props.flash?.info ?? null);

const departmentChanged = computed(() => props.can.department && form.department_id !== props.values.department_id);
const newDepartmentLabel = computed(
    () => props.options.departments.find((o) => o.value === form.department_id)?.label ?? '',
);

const { leaveOpen, confirmLeave, stayOnPage, setSubmitting } = useLeaveGuard(() => form.isDirty);

const focusFirstError = async () => {
    await nextTick();
    document.querySelector<HTMLElement>('#ticket-edit-form [aria-invalid="true"]')?.focus();
};

const submit = () => {
    if (form.processing) return;

    noChanges.value = false;
    if (!form.isDirty) {
        noChanges.value = true;
        return;
    }

    setSubmitting(true);
    form
        .transform((data) => ({
            ...Object.fromEntries((Object.keys(allowed) as Field[]).filter((f) => allowed[f]).map((f) => [f, data[f]])),
            version: data.version,
        }))
        .patch(route('tickets.update', props.ticket.number), {
            preserveScroll: true,
            onError: () => focusFirstError(),
            onFinish: () => setSubmitting(false),
        });
};

/** Descarta o que foi digitado e carrega os valores atuais do servidor. */
const reloadCurrent = () => {
    form.reset();
    router.visit(route('tickets.edit', props.ticket.number), { preserveState: false });
};

const clearError = (field: Field) => {
    noChanges.value = false;
    if (form.errors[field]) form.clearErrors(field);
};

const cancel = () => router.visit(route('tickets.show', props.ticket.number));
</script>

<template>
    <Head :title="`Editar ${ticket.number}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <!-- Cabeçalho -->
            <div class="space-y-3">
                <Link
                    :href="route('tickets.show', ticket.number)"
                    class="inline-flex items-center gap-1 rounded-md text-sm font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                >
                    <Icon name="chevron-left" :size="16" />
                    Voltar para o chamado
                </Link>
                <h1 class="text-2xl font-semibold text-neutral-900">Editar chamado</h1>
                <div class="flex flex-wrap items-center gap-2">
                    <TicketNumber :number="ticket.number" />
                    <StatusBadge :status="ticket.status" />
                    <PriorityBadge :priority="ticket.priority" />
                </div>
                <p class="break-words text-sm text-neutral-600">{{ ticket.title }}</p>
            </div>

            <form id="ticket-edit-form" novalidate @submit.prevent="submit">
                <Card title="Atributos operacionais">
                    <div class="space-y-4">
                        <Alert v-if="otherError('conflict')" variant="danger" title="O chamado foi alterado">
                            <p>{{ otherError('conflict') }}</p>
                            <Button variant="secondary" size="sm" class="mt-3" @click="reloadCurrent">
                                Recarregar dados atuais
                            </Button>
                        </Alert>

                        <Alert v-if="formError" variant="danger" title="Não foi possível salvar">{{ formError }}</Alert>

                        <Alert v-if="noChanges || serverInfo" variant="info">
                            {{ serverInfo ?? 'Nenhuma alteração para salvar.' }}
                        </Alert>

                        <p class="text-sm text-neutral-600">
                            Status, responsável, resolução e encerramento são alterados pelos fluxos próprios
                            do atendimento, não por este formulário.
                        </p>

                        <!-- Prioridade -->
                        <FormField
                            v-if="can.priority"
                            label="Prioridade"
                            required
                            help="A alteração fica registrada no histórico do chamado."
                            :error="form.errors.priority"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <Select
                                :id="id"
                                v-model="form.priority"
                                required
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @change="clearError('priority')"
                            >
                                <option v-for="o in options.priorities" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <FormField v-else label="Prioridade" help="Você não tem permissão para alterar a prioridade." v-slot="{ id, describedby }">
                            <TextInput :id="id" :model-value="ticket.priority" readonly class="bg-neutral-100 text-neutral-700" :aria-describedby="describedby" />
                        </FormField>

                        <!-- Categoria -->
                        <FormField
                            v-if="can.category"
                            label="Categoria"
                            required
                            :error="form.errors.category_id"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <Select
                                :id="id"
                                v-model="form.category_id"
                                required
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @change="clearError('category_id')"
                            >
                                <option v-for="o in options.categories" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <FormField v-else label="Categoria" help="Você não tem permissão para alterar a categoria." v-slot="{ id, describedby }">
                            <TextInput :id="id" :model-value="current.category ?? '—'" readonly class="bg-neutral-100 text-neutral-700" :aria-describedby="describedby" />
                        </FormField>

                        <!-- Departamento -->
                        <FormField
                            v-if="can.department"
                            label="Departamento"
                            required
                            help="O departamento anterior fica registrado no histórico."
                            :error="form.errors.department_id"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <Select
                                :id="id"
                                v-model="form.department_id"
                                required
                                :aria-describedby="describedby"
                                :invalid="invalid"
                                @change="clearError('department_id')"
                            >
                                <option v-for="o in options.departments" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </Select>
                        </FormField>
                        <FormField v-else label="Departamento" help="Você não tem permissão para transferir o chamado de departamento." v-slot="{ id, describedby }">
                            <TextInput :id="id" :model-value="current.department ?? '—'" readonly class="bg-neutral-100 text-neutral-700" :aria-describedby="describedby" />
                        </FormField>

                        <Alert v-if="departmentChanged" variant="warning" title="Transferência de departamento">
                            Ao salvar, o chamado passa para {{ newDepartmentLabel }}. O solicitante, os comentários
                            e o histórico são mantidos.
                        </Alert>
                    </div>

                    <template #footer>
                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                            <Button variant="secondary" class="w-full sm:w-auto" :disabled="form.processing" @click="cancel">
                                Cancelar
                            </Button>
                            <Button type="submit" class="w-full sm:w-auto" :loading="form.processing">
                                {{ form.processing ? 'Salvando…' : 'Salvar alterações' }}
                            </Button>
                        </div>
                    </template>
                </Card>
            </form>
        </div>

        <ConfirmModal
            :show="leaveOpen"
            title="Descartar as alterações?"
            message="As alterações feitas neste formulário ainda não foram salvas e serão perdidas."
            confirm-label="Descartar e sair"
            cancel-label="Continuar editando"
            variant="danger"
            @close="stayOnPage"
            @confirm="confirmLeave"
        />
    </AuthenticatedLayout>
</template>
