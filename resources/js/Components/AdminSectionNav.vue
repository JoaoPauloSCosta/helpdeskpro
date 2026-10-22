<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { adminSections, isAvailable, isCurrent } from '@/lib/navigation';

const page = usePage();

/** Seções que o usuário pode acessar; com uma só, a navegação interna é omitida. */
const sections = computed(() =>
    adminSections
        .filter((section) => isAvailable(section, page.props.auth.permissions))
        .map((section) => ({
            ...section,
            href: route(section.route!),
            current: page.url !== undefined && isCurrent(section, section.route!),
        })),
);
</script>

<template>
    <nav v-if="sections.length > 1" class="border-b border-neutral-200" aria-label="Seções da administração">
        <ul class="-mb-px flex gap-6 overflow-x-auto">
            <li v-for="section in sections" :key="section.label">
                <Link
                    :href="section.href"
                    :aria-current="section.current ? 'page' : undefined"
                    class="inline-flex h-10 items-center whitespace-nowrap border-b-2 px-1 text-sm font-medium transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    :class="
                        section.current
                            ? 'border-primary-500 text-primary-600'
                            : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700'
                    "
                >
                    {{ section.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
