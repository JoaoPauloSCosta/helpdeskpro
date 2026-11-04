<script setup lang="ts">
import { computed, ref } from 'vue';
import DataTable from '@/Components/DataTable.vue';
import { percent, type PerformanceEvolutionPoint, type PerformancePeriod } from '@/lib/performance';

const props = defineProps<{
    points: PerformanceEvolutionPoint[];
    granularity: PerformancePeriod['granularity'];
}>();

const number = (value: number) => value.toLocaleString('pt-BR');

/* ---------- Janela exibida (10, 20 ou 30 dias) ---------- */

const WINDOWS = [10, 20, 30];

/** Só faz sentido na evolução diária; períodos menores limitam as opções ao total de dias. */
const windows = computed(() =>
    props.granularity === 'day' && props.points.length > WINDOWS[0]
        ? [...new Set(WINDOWS.map((n) => Math.min(n, props.points.length)))]
        : [],
);

const chosen = ref(30);
const size = computed(() =>
    windows.value.length ? Math.min(chosen.value, windows.value[windows.value.length - 1]) : props.points.length,
);
const visible = computed(() => props.points.slice(-size.value));

/* ---------- Escala ---------- */

const scale = computed(() => {
    const max = Math.max(1, ...visible.value.flatMap((p) => [p.opened, p.resolved]));
    const raw = max / 4;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const step = Math.max(1, Math.ceil([1, 2, 5, 10].map((m) => m * magnitude).find((s) => s >= raw)!));
    const top = step * Math.ceil(max / step);

    return { top, ticks: Array.from({ length: top / step + 1 }, (_, i) => i * step) };
});

const height = (value: number) => `${(value / scale.value.top) * 100}%`;

/* ---------- Rótulos ---------- */

const shortLabel = (start: string) => {
    const [year, month, day] = start.split('-');

    return props.granularity === 'month' ? `${month}/${year.slice(2)}` : `${day}/${month}`;
};

const longLabel = (start: string) => {
    const [year, month, day] = start.split('-');

    return props.granularity === 'month' ? `${month}/${year}` : props.granularity === 'week' ? `Semana de ${day}/${month}` : `${day}/${month}/${year}`;
};

/** No máximo ~10 rótulos no eixo, para não encavalar com 30 dias. */
const labelStep = computed(() => Math.ceil(visible.value.length / 10));
const showLabel = (index: number) => (visible.value.length - 1 - index) % labelStep.value === 0;

const unitLabel = computed(() => ({ day: 'dia', week: 'semana', month: 'mês' })[props.granularity]);

/* ---------- Leitura (totais da janela ou o intervalo sob o cursor) ---------- */

const hovered = ref<number | null>(null);

const readout = computed(() => {
    const point = hovered.value === null ? null : visible.value[hovered.value];

    if (point) {
        return {
            title: longLabel(point.start),
            opened: point.opened,
            resolved: point.resolved,
            violated: point.violated,
            compliance: percent(point.compliance),
        };
    }

    const sum = (key: 'opened' | 'resolved' | 'violated') => visible.value.reduce((total, p) => total + p[key], 0);

    return {
        title: windows.value.length ? `Últimos ${size.value} dias` : 'Período todo',
        opened: sum('opened'),
        resolved: sum('resolved'),
        violated: sum('violated'),
        compliance: null,
    };
});
</script>

<template>
    <section class="rounded-lg border border-neutral-200 bg-white shadow-sm" aria-labelledby="perf-evolution">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-4 sm:px-6">
            <h2 id="perf-evolution" class="text-lg font-semibold text-neutral-900">
                Evolução no período <span class="text-sm font-normal text-neutral-500">por {{ unitLabel }}</span>
            </h2>

            <div v-if="windows.length" role="group" aria-label="Dias exibidos no gráfico" class="inline-flex rounded-md border border-neutral-300 bg-white p-0.5">
                <button
                    v-for="n in windows"
                    :key="n"
                    type="button"
                    :aria-pressed="n === size"
                    class="h-8 rounded px-3 text-sm font-medium transition-colors duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none"
                    :class="n === size ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900'"
                    @click="chosen = n"
                >
                    {{ n }} dias
                </button>
            </div>
        </header>

        <div class="p-4 sm:p-6">
            <dl class="flex flex-wrap items-end gap-x-6 gap-y-2" aria-live="polite">
                <div class="basis-full text-sm font-medium text-neutral-900 sm:basis-auto sm:me-auto">{{ readout.title }}</div>
                <div class="flex items-center gap-2 text-sm">
                    <span class="size-2.5 rounded-sm bg-primary-500" aria-hidden="true" />
                    <dt class="text-neutral-600">Abertos</dt>
                    <dd class="font-semibold tabular-nums text-neutral-900">{{ number(readout.opened) }}</dd>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <span class="size-2.5 rounded-sm bg-danger" aria-hidden="true" />
                    <dt class="text-neutral-600">Violados</dt>
                    <dd class="font-semibold tabular-nums text-neutral-900">{{ number(readout.violated) }}</dd>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <span class="size-2.5 rounded-sm bg-success" aria-hidden="true" />
                    <dt class="text-neutral-600">Resolvidos</dt>
                    <dd class="font-semibold tabular-nums text-neutral-900">{{ number(readout.resolved) }}</dd>
                </div>
                <div v-if="readout.compliance" class="flex items-center gap-2 text-sm">
                    <dt class="text-neutral-600">SLA</dt>
                    <dd class="font-semibold tabular-nums text-neutral-900">{{ readout.compliance }}</dd>
                </div>
            </dl>

            <!-- Gráfico visual; a versão acessível é a tabela logo abaixo. -->
            <div class="mt-6 grid grid-cols-[auto_1fr] gap-x-2" aria-hidden="true">
                <div class="relative h-56 w-7">
                    <span
                        v-for="tick in scale.ticks"
                        :key="tick"
                        class="absolute right-0 translate-y-1/2 text-xs tabular-nums text-neutral-500"
                        :style="{ bottom: height(tick) }"
                    >
                        {{ number(tick) }}
                    </span>
                </div>

                <div class="relative h-56" @mouseleave="hovered = null">
                    <span
                        v-for="tick in scale.ticks"
                        :key="tick"
                        class="absolute inset-x-0 border-t"
                        :class="tick === 0 ? 'border-neutral-300' : 'border-dashed border-neutral-200'"
                        :style="{ bottom: height(tick) }"
                    />
                    <div class="relative flex h-full items-end gap-0.5 sm:gap-1">
                        <div
                            v-for="(point, index) in visible"
                            :key="point.start"
                            class="flex h-full flex-1 items-end justify-center gap-px rounded-sm transition-colors duration-150 motion-reduce:transition-none"
                            :class="hovered === index && 'bg-neutral-100'"
                            @mouseenter="hovered = index"
                        >
                            <span
                                class="flex w-1/2 max-w-4 flex-col justify-end overflow-hidden rounded-t-sm bg-primary-500 transition-[height] duration-150 motion-reduce:transition-none"
                                :style="{ height: height(point.opened) }"
                            >
                                <span class="block bg-danger" :style="{ height: point.opened ? `${(point.violated / point.opened) * 100}%` : '0' }" />
                            </span>
                            <span
                                class="w-1/2 max-w-4 rounded-t-sm bg-success transition-[height] duration-150 motion-reduce:transition-none"
                                :style="{ height: height(point.resolved) }"
                            />
                        </div>
                    </div>
                </div>

                <div />
                <div class="mt-2 flex gap-0.5 sm:gap-1">
                    <span v-for="(point, index) in visible" :key="point.start" class="flex-1 text-center text-xs tabular-nums text-neutral-500">
                        <span v-if="showLabel(index)" class="whitespace-nowrap">{{ shortLabel(point.start) }}</span>
                    </span>
                </div>
            </div>

            <details class="group mt-6">
                <summary
                    class="cursor-pointer rounded text-sm font-medium text-primary-600 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                >
                    Ver números por {{ unitLabel }}
                </summary>
                <div class="mt-3">
                    <DataTable>
                        <template #head>
                            <tr class="whitespace-nowrap">
                                <th scope="col">Intervalo</th>
                                <th scope="col">Abertos</th>
                                <th scope="col">Resolvidos</th>
                                <th scope="col">Violados</th>
                                <th scope="col">Cumprimento do SLA</th>
                            </tr>
                        </template>
                        <tr v-for="point in visible" :key="point.start">
                            <td>{{ longLabel(point.start) }}</td>
                            <td class="tabular-nums">{{ number(point.opened) }}</td>
                            <td class="tabular-nums">{{ number(point.resolved) }}</td>
                            <td class="tabular-nums">{{ number(point.violated) }}</td>
                            <td class="tabular-nums">{{ percent(point.compliance) }}</td>
                        </tr>
                    </DataTable>
                </div>
            </details>
        </div>
    </section>
</template>
