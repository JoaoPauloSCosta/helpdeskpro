<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import CheckboxField from '@/Components/CheckboxField.vue';
import CommentCard from '@/Components/CommentCard.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import JustificationModal from '@/Components/JustificationModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import PriorityBadge from '@/Components/PriorityBadge.vue';
import RadioField from '@/Components/RadioField.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Select from '@/Components/Select.vue';
import SlaIndicator from '@/Components/SlaIndicator.vue';
import SortableHeader from '@/Components/SortableHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Switch from '@/Components/Switch.vue';
import Tabs from '@/Components/Tabs.vue';
import Textarea from '@/Components/Textarea.vue';
import TextInput from '@/Components/TextInput.vue';
import Timeline from '@/Components/Timeline.vue';
import TimelineItem from '@/Components/TimelineItem.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import TicketNumber from '@/Components/TicketNumber.vue';
import { useToast } from '@/composables/useToast';
import {
    ticketPriorities,
    ticketStatuses,
    type SlaState,
    type TicketPriority,
    type TicketStatus,
} from '@/lib/ticket';

const toast = useToast();

const sections = [
    ['cores', 'Cores'],
    ['tipografia', 'Tipografia'],
    ['icones', 'Ícones'],
    ['botoes', 'Botões'],
    ['formularios', 'Formulários'],
    ['badges', 'Badges e SLA'],
    ['alertas', 'Alertas e toasts'],
    ['cards', 'Cards e indicadores'],
    ['tabela', 'Tabela de dados'],
    ['modais', 'Modais'],
    ['comentarios', 'Comentários e histórico'],
    ['abas', 'Abas'],
];

/* Cores — classes escritas por extenso para o Tailwind encontrá-las. */
const palettes = [
    {
        name: 'Primária',
        swatches: [
            ['primary-50', 'bg-primary-50', '#EBF4FF'],
            ['primary-100', 'bg-primary-100', '#D6E8FF'],
            ['primary-200', 'bg-primary-200', '#ADD1FF'],
            ['primary-300', 'bg-primary-300', '#7AB4FF'],
            ['primary-400', 'bg-primary-400', '#3D93FF'],
            ['primary-500', 'bg-primary-500', '#0075F2'],
            ['primary-600', 'bg-primary-600', '#005FC4'],
            ['primary-700', 'bg-primary-700', '#004A9A'],
            ['primary-800', 'bg-primary-800', '#003672'],
            ['primary-900', 'bg-primary-900', '#00234A'],
        ],
    },
    {
        name: 'Neutras',
        swatches: [
            ['app-bg / neutral-50', 'bg-neutral-50', '#FAFAFA'],
            ['neutral-100', 'bg-neutral-100', '#F4F4F5'],
            ['neutral-200', 'bg-neutral-200', '#E4E4E7'],
            ['neutral-300', 'bg-neutral-300', '#D4D4D8'],
            ['neutral-400', 'bg-neutral-400', '#A1A1AA'],
            ['neutral-500', 'bg-neutral-500', '#71717A'],
            ['neutral-600', 'bg-neutral-600', '#52525B'],
            ['neutral-700', 'bg-neutral-700', '#3F3F46'],
            ['neutral-800', 'bg-neutral-800', '#27272A'],
            ['neutral-900', 'bg-neutral-900', '#18181B'],
        ],
    },
    {
        name: 'Semânticas',
        swatches: [
            ['success', 'bg-success', '#16A34A'],
            ['success-bg', 'bg-success-bg', '#DCFCE7'],
            ['warning', 'bg-warning', '#D97706'],
            ['warning-bg', 'bg-warning-bg', '#FEF3C7'],
            ['danger', 'bg-danger', '#DC2626'],
            ['danger-bg', 'bg-danger-bg', '#FEE2E2'],
        ],
    },
];

const typeScale = [
    ['Título de página', 'text-2xl font-semibold', 'text-2xl · 600'],
    ['Título de seção', 'text-lg font-semibold', 'text-lg · 600'],
    ['Subtítulo', 'text-base font-medium', 'text-base · 500'],
    ['Corpo da interface', 'text-sm', 'text-sm · 400 (padrão)'],
    ['Rótulo de formulário', 'text-sm font-medium', 'text-sm · 500'],
    ['Texto auxiliar e legenda', 'text-xs text-neutral-500', 'text-xs · 400'],
];

const iconNames = [
    'check', 'x', 'plus', 'chevron-down', 'chevron-left', 'chevron-right', 'search',
    'warning', 'info', 'check-circle', 'x-circle', 'lock', 'clock', 'pause', 'inbox',
    'download', 'pencil', 'arrow-up', 'arrow-down', 'copy',
] as const;

/* Formulários */
const form = reactive({
    title: '',
    email: 'nome@empresa.com',
    category: '',
    description: '',
    withError: 'valor inválido',
    agree: true,
    channel: 'email' as string | number | null,
    active: true,
});
const search = ref('');

/* Tabela */
interface Row {
    number: string;
    title: string;
    requester: string;
    priority: TicketPriority;
    status: TicketStatus;
    sla: SlaState;
    slaLabel?: string;
}

const rows: Row[] = [
    { number: 'HD-7K4M9Q2X', title: 'Impressora do 2º andar sem toner', requester: 'Ana Souza', priority: 'Baixa', status: 'Aberto', sla: 'on_track', slaLabel: 'Vence em 7h' },
    { number: 'HD-3P8R5W6T', title: 'Sem acesso ao sistema financeiro', requester: 'Carlos Lima', priority: 'Crítica', status: 'Em atendimento', sla: 'overdue', slaLabel: 'Vencido há 35min' },
    { number: 'HD-9H2D4F7K', title: 'Solicitação de novo notebook', requester: 'Marina Alves', priority: 'Média', status: 'Pendente Departamentos', sla: 'paused' },
    { number: 'HD-5N6V3C8B', title: 'VPN desconecta a cada 10 minutos', requester: 'Pedro Rocha', priority: 'Alta', status: 'Em atendimento', sla: 'at_risk', slaLabel: 'Vence em 1h 20min' },
    { number: 'HD-2Q7X9M4L', title: 'Erro ao emitir nota fiscal', requester: 'Julia Prado', priority: 'Alta', status: 'Aguardando solicitante', sla: 'paused' },
    { number: 'HD-8T4K6Y3S', title: 'Criar e-mail para novo colaborador', requester: 'Rafael Dias', priority: 'Baixa', status: 'Resolvido', sla: 'met' },
    { number: 'HD-6W3Z8J5G', title: 'Troca de monitor com defeito', requester: 'Lucia Faria', priority: 'Média', status: 'Pendente Terceiros', sla: 'paused' },
    { number: 'HD-4C9B2N7R', title: 'Planilha de estoque não abre', requester: 'Bruno Melo', priority: 'Média', status: 'Encerrado', sla: 'met' },
    { number: 'HD-7M5F3H9D', title: 'Wi-Fi instável na sala de reunião', requester: 'Camila Nunes', priority: 'Média', status: 'Pendente', sla: 'paused' },
    { number: 'HD-3L8S6P2Q', title: 'Permissão de pasta compartilhada', requester: 'Diego Castro', priority: 'Baixa', status: 'Aberto', sla: 'on_track', slaLabel: 'Vence em 22h' },
    { number: 'HD-9K4T7V5X', title: 'Instalar software de design', requester: 'Elisa Moura', priority: 'Baixa', status: 'Em atendimento', sla: 'on_track', slaLabel: 'Vence em 5h' },
    { number: 'HD-2R6G8Z4W', title: 'Ramal sem sinal', requester: 'Fábio Reis', priority: 'Alta', status: 'Aberto', sla: 'at_risk', slaLabel: 'Vence em 40min' },
];

const perPage = 5;
const page = ref(1);
const sort = reactive<{ key: keyof Row; dir: 'asc' | 'desc' }>({ key: 'number', dir: 'asc' });
const showEmptyTable = ref(false);

const toggleSort = (key: keyof Row) => {
    if (sort.key === key) {
        sort.dir = sort.dir === 'asc' ? 'desc' : 'asc';
    } else {
        sort.key = key;
        sort.dir = 'asc';
    }
};

const visibleRows = computed(() => {
    if (showEmptyTable.value) return [];
    const sorted = [...rows].sort((a, b) => String(a[sort.key]).localeCompare(String(b[sort.key]), 'pt-BR'));
    if (sort.dir === 'desc') sorted.reverse();
    return sorted.slice((page.value - 1) * perPage, page.value * perPage);
});

const direction = (key: keyof Row) => (sort.key === key ? sort.dir : null);

/* Modais */
const confirmOpen = ref(false);
const dangerOpen = ref(false);
const reasonOpen = ref(false);
const processing = ref(false);

const fakeRequest = (done: () => void, message: string) => {
    processing.value = true;
    setTimeout(() => {
        processing.value = false;
        done();
        toast.success(message);
    }, 900);
};

const closeConfirm = () => (confirmOpen.value = false);
const closeDanger = () => (dangerOpen.value = false);
const closeReason = () => (reasonOpen.value = false);

/* Abas */
const tab = ref('comentarios');
const tabs = [
    { key: 'comentarios', label: 'Comentários', count: 3 },
    { key: 'historico', label: 'Histórico', count: 7 },
    { key: 'sla', label: 'SLA' },
];

const loadingButton = ref(false);
const simulateLoading = () => {
    loadingButton.value = true;
    setTimeout(() => (loadingButton.value = false), 1500);
};
</script>

<template>
    <Head title="Design System" />

    <div class="min-h-screen bg-app-bg text-neutral-900">
        <header class="border-b border-neutral-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-primary-600">Help Desk Pro</p>
                    <h1 class="text-xl font-semibold">Design System — referência de componentes</h1>
                </div>
                <Badge tone="warning">Somente ambiente local</Badge>
            </div>
        </header>

        <div class="mx-auto flex max-w-7xl gap-8 px-4 py-8 sm:px-6 lg:px-8">
            <nav class="sticky top-8 hidden h-fit w-48 shrink-0 lg:block" aria-label="Seções">
                <ul class="space-y-1 text-sm">
                    <li v-for="[id, label] in sections" :key="id">
                        <a
                            :href="`#${id}`"
                            class="block rounded-md px-3 py-1.5 text-neutral-600 hover:bg-primary-50 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        >
                            {{ label }}
                        </a>
                    </li>
                </ul>
            </nav>

            <main class="min-w-0 flex-1 space-y-12">
                <Alert variant="info" title="Como usar esta página">
                    Esta é a referência visual dos componentes. Toda tela nova deve montar a interface
                    apenas com estes componentes e tokens (<code class="font-mono text-xs">docs/design-system.md</code>).
                    Se faltar algo, o componente é criado aqui primeiro.
                </Alert>

                <!-- CORES -->
                <section id="cores" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Cores</h2>
                    <div v-for="palette in palettes" :key="palette.name">
                        <h3 class="mb-2 text-sm font-medium text-neutral-600">{{ palette.name }}</h3>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                            <div v-for="[name, cls, hex] in palette.swatches" :key="name">
                                <div class="h-12 rounded-md border border-neutral-200" :class="cls" />
                                <p class="mt-1 text-xs font-medium">{{ name }}</p>
                                <p class="font-mono text-xs text-neutral-500">{{ hex }}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- TIPOGRAFIA -->
                <section id="tipografia" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Tipografia — Figtree</h2>
                    <Card>
                        <div class="space-y-4">
                            <div
                                v-for="[label, cls, spec] in typeScale"
                                :key="label"
                                class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between"
                            >
                                <span :class="cls">{{ label }}</span>
                                <span class="font-mono text-xs text-neutral-500">{{ spec }}</span>
                            </div>
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                                <TicketNumber number="HD-7K4M9Q2X" />
                                <span class="font-mono text-xs text-neutral-500">font-mono · text-sm · 500</span>
                            </div>
                        </div>
                    </Card>
                </section>

                <!-- ÍCONES -->
                <section id="icones" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Ícones</h2>
                    <Card>
                        <div class="grid grid-cols-3 gap-4 sm:grid-cols-5 lg:grid-cols-7">
                            <div v-for="name in iconNames" :key="name" class="flex flex-col items-center gap-2 text-neutral-700">
                                <Icon :name="name" :size="24" />
                                <span class="text-xs text-neutral-500">{{ name }}</span>
                            </div>
                        </div>
                        <p class="mt-4 text-xs text-neutral-500">
                            Tamanhos: 16 (inline), 20 (botões e navegação, padrão), 24 (estados vazios). Ícones sem texto precisam de <code class="font-mono">label</code>.
                        </p>
                    </Card>
                </section>

                <!-- BOTÕES -->
                <section id="botoes" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Botões</h2>
                    <Card>
                        <div class="space-y-6">
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">Variantes</h3>
                                <div class="flex flex-wrap items-center gap-3">
                                    <Button variant="primary">Primário</Button>
                                    <Button variant="secondary">Secundário</Button>
                                    <Button variant="danger">Perigo</Button>
                                    <Button variant="ghost">Fantasma</Button>
                                </div>
                            </div>
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">Tamanhos</h3>
                                <div class="flex flex-wrap items-center gap-3">
                                    <Button size="sm">Compacto (h-8)</Button>
                                    <Button size="md">Padrão (h-10)</Button>
                                    <Button size="lg">Grande (h-12)</Button>
                                </div>
                            </div>
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">Estados</h3>
                                <div class="flex flex-wrap items-center gap-3">
                                    <Button disabled>Desabilitado</Button>
                                    <Button variant="secondary" disabled>Desabilitado</Button>
                                    <Button :loading="loadingButton" @click="simulateLoading">
                                        {{ loadingButton ? 'Salvando…' : 'Clique para carregar' }}
                                    </Button>
                                </div>
                            </div>
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">Com ícone e largura total</h3>
                                <div class="space-y-3">
                                    <div class="flex flex-wrap gap-3">
                                        <Button><Icon name="plus" :size="16" /> Abrir chamado</Button>
                                        <Button variant="secondary"><Icon name="download" :size="16" /> Exportar CSV</Button>
                                        <Button variant="ghost" size="sm"><Icon name="pencil" :size="16" /> Editar</Button>
                                    </div>
                                    <Button block>Largura total (ações principais no mobile)</Button>
                                </div>
                            </div>
                        </div>
                    </Card>
                    <p class="text-xs text-neutral-500">
                        Uma ação primária por tela. <code class="font-mono">PrimaryButton</code>,
                        <code class="font-mono">SecondaryButton</code> e <code class="font-mono">DangerButton</code>
                        são atalhos do <code class="font-mono">Button</code> usados pelas telas do Breeze.
                    </p>
                </section>

                <!-- FORMULÁRIOS -->
                <section id="formularios" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Formulários</h2>
                    <Card>
                        <div class="grid gap-6 md:grid-cols-2">
                            <FormField label="Título" required help="Resuma o problema em uma frase." v-slot="{ id, describedby, invalid }">
                                <TextInput :id="id" v-model="form.title" placeholder="Ex.: Sem acesso ao sistema" :aria-describedby="describedby" :invalid="invalid" />
                            </FormField>

                            <FormField label="E-mail" v-slot="{ id, describedby }">
                                <TextInput :id="id" v-model="form.email" type="email" :aria-describedby="describedby" />
                            </FormField>

                            <FormField label="Categoria" required v-slot="{ id, describedby, invalid }">
                                <Select :id="id" v-model="form.category" placeholder="Selecione uma categoria" :aria-describedby="describedby" :invalid="invalid">
                                    <option value="hardware">Hardware</option>
                                    <option value="software">Software</option>
                                    <option value="rede">Rede</option>
                                </Select>
                            </FormField>

                            <FormField label="Campo com erro" required error="Este valor não é aceito. Revise e tente novamente." v-slot="{ id, describedby, invalid }">
                                <TextInput :id="id" v-model="form.withError" :aria-describedby="describedby" :invalid="invalid" />
                            </FormField>

                            <FormField label="Campo desabilitado" v-slot="{ id }">
                                <TextInput :id="id" model-value="Não editável" disabled />
                            </FormField>

                            <FormField label="Pesquisa" v-slot="{ id }">
                                <SearchInput :id="id" v-model="search" placeholder="Buscar por título ou HD-…" />
                            </FormField>

                            <div class="md:col-span-2">
                                <FormField label="Descrição" required help="Quanto mais detalhes, mais rápido o atendimento." v-slot="{ id, describedby, invalid }">
                                    <Textarea :id="id" v-model="form.description" :aria-describedby="describedby" :invalid="invalid" />
                                </FormField>
                            </div>

                            <fieldset class="space-y-3">
                                <legend class="text-sm font-medium text-neutral-700">Canal de retorno</legend>
                                <RadioField v-model="form.channel" value="email" label="E-mail" />
                                <RadioField v-model="form.channel" value="telefone" label="Telefone" description="Somente em horário comercial." />
                            </fieldset>

                            <div class="space-y-4">
                                <CheckboxField v-model="form.agree" label="Receber notificações" description="Avisos de atualização do chamado." />
                                <Switch v-model="form.active" label="Categoria ativa" />
                            </div>
                        </div>
                        <template #footer>
                            <div class="flex justify-end gap-3">
                                <Button variant="secondary">Cancelar</Button>
                                <Button>Salvar</Button>
                            </div>
                        </template>
                    </Card>
                </section>

                <!-- BADGES -->
                <section id="badges" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Badges, prioridade e SLA</h2>
                    <Card>
                        <div class="space-y-6">
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">StatusBadge — 8 status do RF-10</h3>
                                <div class="flex flex-wrap gap-2">
                                    <StatusBadge v-for="status in ticketStatuses" :key="status" :status="status" />
                                </div>
                            </div>
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">PriorityBadge — nomes a confirmar</h3>
                                <div class="flex flex-wrap gap-2">
                                    <PriorityBadge v-for="priority in ticketPriorities" :key="priority" :priority="priority" />
                                </div>
                            </div>
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">SlaIndicator</h3>
                                <div class="flex flex-wrap gap-2">
                                    <SlaIndicator state="on_track" label="Vence em 7h" />
                                    <SlaIndicator state="at_risk" label="Vence em 1h 20min" />
                                    <SlaIndicator state="overdue" label="Vencido há 35min" />
                                    <SlaIndicator state="paused" />
                                    <SlaIndicator state="met" />
                                </div>
                            </div>
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">Badge genérico (tons)</h3>
                                <div class="flex flex-wrap gap-2">
                                    <Badge tone="neutral">Neutro</Badge>
                                    <Badge tone="info">Info</Badge>
                                    <Badge tone="primary">Primário</Badge>
                                    <Badge tone="success">Sucesso</Badge>
                                    <Badge tone="warning">Alerta</Badge>
                                    <Badge tone="danger">Erro</Badge>
                                </div>
                            </div>
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">TicketNumber</h3>
                                <TicketNumber number="HD-7K4M9Q2X" copyable />
                            </div>
                        </div>
                    </Card>
                </section>

                <!-- ALERTAS E TOASTS -->
                <section id="alertas" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Alertas e toasts</h2>

                    <Card title="Toast — padrão único de notificação operacional">
                        <div class="space-y-4">
                            <p class="text-sm text-neutral-600">
                                Todo resultado de uma operação (sucesso, erro, alerta ou informação) é um toast.
                                O padrão é o dos chamados: canto superior direito, some após 5s (inclusive erros),
                                fecha pelo botão, ícone e borda conforme a variante.
                            </p>
                            <div class="flex flex-wrap gap-3">
                                <Button variant="secondary" @click="toast.success('Chamado salvo com sucesso.')">Sucesso</Button>
                                <Button variant="secondary" @click="toast.info('Nenhuma alteração para salvar.')">Informação</Button>
                                <Button variant="secondary" @click="toast.warning('Não foi possível enviar o e-mail. O usuário pode usar “Esqueci minha senha”.')">Alerta</Button>
                                <Button variant="secondary" @click="toast.error('Você não tem permissão para esta ação.')">Erro</Button>
                            </div>
                            <div>
                                <h3 class="mb-3 text-sm font-medium text-neutral-600">Mensagens padronizadas</h3>
                                <div class="flex flex-wrap gap-3">
                                    <Button variant="secondary" @click="toast.formErrors()">Validação no formulário</Button>
                                    <Button variant="secondary" @click="toast.unexpected()">Falha inesperada</Button>
                                    <Button
                                        variant="secondary"
                                        @click="
                                            toast.success('Mensagem repetida.');
                                            toast.success('Mensagem repetida.');
                                        "
                                    >
                                        Disparo duplicado (aparece uma vez)
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <Card title="Como usar">
                        <ul class="list-disc space-y-2 ps-5 text-sm text-neutral-600">
                            <li>
                                <strong class="font-medium text-neutral-900">Mensagem do servidor:</strong> use
                                <code class="font-mono text-xs">->with('success' | 'info' | 'warning', …)</code> no controller. O
                                <code class="font-mono text-xs">ToastContainer</code> dos layouts exibe; a tela não precisa tratar.
                            </li>
                            <li>
                                <strong class="font-medium text-neutral-900">Erros devolvidos pelo servidor:</strong> viram toast
                                automaticamente (a mensagem, se for uma só, ou “Corrija os campos destacados”). O erro de cada campo continua junto do campo.
                            </li>
                            <li>
                                <strong class="font-medium text-neutral-900">Na tela:</strong>
                                <code class="font-mono text-xs">useToast()</code> com
                                <code class="font-mono text-xs">success</code>, <code class="font-mono text-xs">info</code>,
                                <code class="font-mono text-xs">warning</code>, <code class="font-mono text-xs">error</code>,
                                <code class="font-mono text-xs">formErrors()</code> (validação no navegador) e
                                <code class="font-mono text-xs">unexpected()</code> (rede ou 5xx).
                            </li>
                            <li>
                                Mensagens objetivas, sem detalhes técnicos. Mensagens idênticas já visíveis não se repetem.
                            </li>
                        </ul>
                    </Card>

                    <div>
                        <h3 class="mb-3 text-sm font-medium text-neutral-600">
                            Alerta em linha — somente para o que não é notificação de operação
                        </h3>
                        <div class="space-y-3">
                            <Alert variant="info">Orientação ou ajuda ao preencher: o status passará para Em atendimento.</Alert>
                            <Alert variant="warning" title="A categoria será inativada">
                                Aviso antes de confirmar uma ação, ou estado que afeta a tela (ex.: nenhum papel disponível).
                            </Alert>
                            <Alert variant="danger" title="O chamado foi alterado">
                                Conflito com ação para o usuário decidir (ex.: recarregar dados atuais), ou falha ao carregar uma lista.
                            </Alert>
                        </div>
                        <p class="mt-3 text-xs text-neutral-500">
                            Confirmações, indicadores de carregamento, estados vazios e textos de ajuda continuam como estão.
                            Eles não substituem o toast como retorno de uma operação.
                        </p>
                    </div>
                </section>

                <!-- CARDS -->
                <section id="cards" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Cards, cabeçalho de página e indicadores</h2>
                    <Card title="Cabeçalho de página (PageHeader)">
                        <PageHeader title="Chamados" subtitle="Acompanhe e gerencie as solicitações de suporte.">
                            <Button variant="secondary"><Icon name="download" :size="16" /> Exportar CSV</Button>
                            <Button><Icon name="plus" :size="16" /> Abrir chamado</Button>
                        </PageHeader>
                    </Card>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard label="Total de chamados" :value="248" />
                        <StatCard label="Em atendimento" :value="37" />
                        <StatCard label="Fora do SLA" :value="9" hint="3,6% do total" />
                        <StatCard label="Tempo médio de 1ª resposta" value="42 min" />
                    </div>
                    <Card title="Card com cabeçalho e rodapé">
                        <template #header>
                            <h2 class="text-lg font-semibold">Card com cabeçalho e rodapé</h2>
                            <Badge tone="info">Opcional</Badge>
                        </template>
                        Conteúdo do card. Superfície branca, borda neutra e sombra discreta.
                        <template #footer>
                            <p class="text-xs text-neutral-500">Rodapé com informações auxiliares.</p>
                        </template>
                    </Card>
                </section>

                <!-- TABELA -->
                <section id="tabela" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Tabela de dados, filtros e paginação</h2>

                    <Card>
                        <div class="grid gap-4 md:grid-cols-4">
                            <div class="md:col-span-2">
                                <SearchInput v-model="search" placeholder="Buscar por título, solicitante ou HD-…" aria-label="Buscar chamados" />
                            </div>
                            <Select model-value="" aria-label="Status" placeholder="Todos os status">
                                <option v-for="status in ticketStatuses" :key="status" :value="status">{{ status }}</option>
                            </Select>
                            <Select model-value="" aria-label="Prioridade" placeholder="Todas as prioridades">
                                <option v-for="priority in ticketPriorities" :key="priority" :value="priority">{{ priority }}</option>
                            </Select>
                        </div>
                    </Card>

                    <div class="flex items-center justify-end">
                        <Switch v-model="showEmptyTable" label="Simular lista vazia" />
                    </div>

                    <DataTable>
                        <template #head>
                            <tr>
                                <SortableHeader label="ID" :direction="direction('number')" @sort="toggleSort('number')" />
                                <SortableHeader label="Título" :direction="direction('title')" @sort="toggleSort('title')" />
                                <SortableHeader label="Solicitante" :direction="direction('requester')" @sort="toggleSort('requester')" />
                                <SortableHeader label="Prioridade" :direction="direction('priority')" @sort="toggleSort('priority')" />
                                <SortableHeader label="Status" :direction="direction('status')" @sort="toggleSort('status')" />
                                <th scope="col">SLA</th>
                            </tr>
                        </template>

                        <tr v-for="row in visibleRows" :key="row.number">
                            <td><TicketNumber :number="row.number" /></td>
                            <td class="font-medium">{{ row.title }}</td>
                            <td class="text-neutral-600">{{ row.requester }}</td>
                            <td><PriorityBadge :priority="row.priority" /></td>
                            <td><StatusBadge :status="row.status" /></td>
                            <td><SlaIndicator :state="row.sla" :label="row.slaLabel" /></td>
                        </tr>

                        <template #footer>
                            <EmptyState
                                v-if="visibleRows.length === 0"
                                title="Nenhum chamado encontrado"
                                description="Ajuste a pesquisa ou os filtros, ou abra um novo chamado."
                            >
                                <Button><Icon name="plus" :size="16" /> Abrir chamado</Button>
                            </EmptyState>
                            <Pagination
                                v-model:page="page"
                                :per-page="perPage"
                                :total="showEmptyTable ? 0 : rows.length"
                            />
                        </template>
                    </DataTable>
                    <p class="text-xs text-neutral-500">
                        Em telas pequenas a tabela rola na horizontal. A versão em cards para mobile será criada junto com a listagem de chamados (CHA-01).
                    </p>
                </section>

                <!-- MODAIS -->
                <section id="modais" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Modais</h2>
                    <Card>
                        <div class="flex flex-wrap gap-3">
                            <Button variant="secondary" @click="confirmOpen = true">Confirmação</Button>
                            <Button variant="secondary" @click="dangerOpen = true">Confirmação de perigo</Button>
                            <Button variant="secondary" @click="reasonOpen = true">Justificativa obrigatória</Button>
                        </div>
                    </Card>

                    <ConfirmModal
                        :show="confirmOpen"
                        title="Assumir este chamado?"
                        message="O chamado será atribuído a você e o status passará para Em atendimento."
                        confirm-label="Assumir chamado"
                        :processing="processing"
                        @close="closeConfirm"
                        @confirm="fakeRequest(closeConfirm, 'Chamado assumido.')"
                    />
                    <ConfirmModal
                        :show="dangerOpen"
                        title="Encerrar chamado?"
                        message="O chamado encerrado não poderá ser reaberto pelo solicitante."
                        confirm-label="Encerrar"
                        variant="danger"
                        :processing="processing"
                        @close="closeDanger"
                        @confirm="fakeRequest(closeDanger, 'Chamado encerrado.')"
                    />
                    <JustificationModal
                        :show="reasonOpen"
                        title="Reabrir chamado"
                        description="Explique por que a solução não resolveu o problema. A justificativa fica registrada no histórico."
                        label="Justificativa da reabertura"
                        confirm-label="Reabrir chamado"
                        :processing="processing"
                        @close="closeReason"
                        @confirm="fakeRequest(closeReason, 'Chamado reaberto.')"
                    />
                </section>

                <!-- COMENTÁRIOS -->
                <section id="comentarios" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Comentários, notas internas e linha do tempo</h2>
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="space-y-3">
                            <CommentCard
                                author="Ana Souza"
                                date="21/10/2026 09:12"
                                body="Boa tarde, a impressora do segundo andar parou de imprimir desde ontem."
                            />
                            <CommentCard
                                author="Carlos Lima (Suporte)"
                                date="21/10/2026 09:40"
                                body="Olá, Ana. Já estamos verificando o equipamento e retornamos em breve."
                                edited
                                can-view-history
                                @show-history="toast.info('Abre o histórico de versões do comentário.')"
                            />
                            <CommentCard
                                internal
                                author="Carlos Lima (Suporte)"
                                date="21/10/2026 09:45"
                                body="Toner esgotado. Verificar estoque com o almoxarifado antes de responder."
                            />
                        </div>

                        <Card title="Histórico do chamado">
                            <Timeline>
                                <TimelineItem title="Chamado aberto" author="Ana Souza" date="21/10/2026 09:12" />
                                <TimelineItem title="Chamado assumido" author="Carlos Lima" date="21/10/2026 09:30">
                                    Status: Aberto → Em atendimento
                                </TimelineItem>
                                <TimelineItem title="Prioridade alterada" author="Carlos Lima" date="21/10/2026 09:50">
                                    Baixa → Média · nova meta de resolução: 8h
                                </TimelineItem>
                                <TimelineItem tone="danger" title="Prazo de primeira resposta vencido" date="21/10/2026 10:30" />
                                <TimelineItem tone="success" title="Chamado resolvido" author="Carlos Lima" date="21/10/2026 14:05">
                                    Toner substituído e impressão testada.
                                </TimelineItem>
                                <TimelineItem tone="neutral" title="Categoria alterada" author="Gestor" date="21/10/2026 14:20">
                                    Hardware → Impressoras
                                </TimelineItem>
                            </Timeline>
                        </Card>
                    </div>
                </section>

                <!-- ABAS -->
                <section id="abas" class="scroll-mt-8 space-y-4">
                    <h2 class="text-lg font-semibold">Abas</h2>
                    <Card>
                        <Tabs v-model="tab" :tabs="tabs" />
                        <p class="pt-4 text-sm text-neutral-600">
                            Aba ativa: <span class="font-medium text-neutral-900">{{ tab }}</span>
                        </p>
                    </Card>
                </section>
            </main>
        </div>

        <ToastContainer />
    </div>
</template>
