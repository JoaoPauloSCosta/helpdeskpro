<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';
import Icon from '@/Components/Icon.vue';
import { isCurrent, navigation, resolveRoute } from '@/lib/navigation';

const page = usePage();

// Depende de page.url para recalcular a cada navegação.
const items = computed(() =>
    navigation
        .map((item) => ({ item, target: resolveRoute(item, page.props.auth.permissions) }))
        .filter(({ target }) => target !== null)
        .map(({ item, target }) => ({
            ...item,
            href: route(target!),
            current: page.url !== undefined && isCurrent(item, target!),
        })),
);
</script>

<template>
    <nav class="flex h-full flex-col bg-white" aria-label="Navegação principal">
        <div class="flex h-16 shrink-0 items-center border-b border-neutral-200 px-4">
            <Link
                :href="route('dashboard')"
                class="rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            >
                <BrandMark />
            </Link>
        </div>

        <ul class="flex-1 space-y-1 overflow-y-auto p-3">
            <li v-for="item in items" :key="item.label">
                <Link
                    :href="item.href"
                    :aria-current="item.current ? 'page' : undefined"
                    class="relative flex h-10 items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    :class="
                        item.current
                            ? 'bg-primary-50 text-primary-700'
                            : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900'
                    "
                >
                    <span
                        v-if="item.current"
                        class="absolute inset-y-1 start-0 w-[3px] rounded-full bg-primary-500"
                        aria-hidden="true"
                    />
                    <Icon :name="item.icon" />
                    {{ item.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
