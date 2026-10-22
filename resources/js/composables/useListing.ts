import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

export type Direction = 'asc' | 'desc';

interface ListingOptions<K extends string, S extends string> {
    /** Rota Laravel da listagem. */
    routeName: string;
    filterKeys: readonly K[];
    /** Filtros e ordenação aplicados, vindos do backend (props da página). */
    filters: () => Partial<Record<K, string>>;
    sort: () => { key: S; direction: Direction };
    defaultSort: { key: S; direction: Direction };
    /** Campos que começam em ordem decrescente ao serem escolhidos (ex.: datas). */
    descendingFirst?: S[];
}

/**
 * Pesquisa, filtros, ordenação e paginação no servidor para as listagens administrativas.
 * Mantém o formulário sincronizado com os filtros aplicados e transforma falhas de rede ou
 * respostas 5xx em um estado de erro com nova tentativa.
 */
export function useListing<K extends string, S extends string>(options: ListingOptions<K, S>) {
    const page = usePage();

    const toForm = (filters: Partial<Record<K, string>>) =>
        Object.fromEntries(options.filterKeys.map((key) => [key, filters[key] ?? ''])) as Record<K, string>;

    const form = reactive(toForm(options.filters())) as Record<K, string>;

    const serverErrors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

    const hasAppliedFilters = computed(() => Object.keys(options.filters()).length > 0);
    const formIsEmpty = computed(() => options.filterKeys.every((key) => !form[key]));

    // Sincroniza o formulário com os filtros aplicados (inclusive no voltar/avançar do navegador),
    // exceto quando a validação falhou, para o usuário corrigir o que digitou.
    watch(options.filters, (filters) => {
        if (Object.keys(serverErrors.value).length === 0) Object.assign(form, toForm(filters));
    });

    const processing = ref(false);
    const loadError = ref(false);
    let lastParams: Record<string, string | number> = {};

    const compact = (values: Record<string, string | number | undefined>) =>
        Object.fromEntries(Object.entries(values).filter(([, v]) => v !== undefined && v !== '')) as Record<
            string,
            string | number
        >;

    /** Ordenação só vai para a URL quando difere do padrão. */
    const sortParams = (key: S, direction: Direction) =>
        key === options.defaultSort.key && direction === options.defaultSort.direction ? {} : { sort: key, direction };

    const currentSortParams = () => sortParams(options.sort().key, options.sort().direction);

    const visit = (params: Record<string, string | number | undefined>) => {
        lastParams = compact(params);
        loadError.value = false;

        // Uma nova visita cancela a anterior no Inertia; os botões também ficam bloqueados enquanto carrega.
        router.get(route(options.routeName), lastParams, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        });
    };

    const retry = () => visit(lastParams);

    const submitFilters = () => {
        if (processing.value) return;
        // Novo conjunto de filtros volta para a primeira página.
        visit({ ...form, ...currentSortParams() });
    };

    const clearFilters = () => {
        Object.assign(form, toForm({}));
        visit({});
    };

    const removeFilter = (key: K) => {
        form[key] = '';
        visit({ ...options.filters(), [key]: undefined, ...currentSortParams() });
    };

    const sortBy = (key: S, direction?: Direction) => {
        const { key: currentKey, direction: currentDirection } = options.sort();
        const next =
            direction ??
            (currentKey === key
                ? currentDirection === 'asc'
                    ? 'desc'
                    : 'asc'
                : options.descendingFirst?.includes(key)
                  ? 'desc'
                  : 'asc');
        visit({ ...options.filters(), ...sortParams(key, next) });
    };

    const goToPage = (target: number) => visit({ ...options.filters(), ...currentSortParams(), page: target });

    const direction = (key: S) => (options.sort().key === key ? options.sort().direction : null);

    /** Ordenação pelo seletor do celular, onde a tabela vira cartões. */
    const mobileSort = computed({
        get: () => options.sort().key,
        set: (key: S) => sortBy(key, options.sort().key === key ? options.sort().direction : undefined),
    });

    const removers: (() => void)[] = [];
    onMounted(() => {
        removers.push(
            router.on('exception', (event) => {
                event.preventDefault();
                loadError.value = true;
            }),
            router.on('invalid', (event) => {
                if (event.detail.response.status >= 500) {
                    event.preventDefault();
                    loadError.value = true;
                }
            }),
        );
    });
    onUnmounted(() => removers.forEach((remove) => remove()));

    return {
        form,
        serverErrors,
        hasAppliedFilters,
        formIsEmpty,
        processing,
        loadError,
        retry,
        submitFilters,
        clearFilters,
        removeFilter,
        sortBy,
        goToPage,
        direction,
        mobileSort,
    };
}
