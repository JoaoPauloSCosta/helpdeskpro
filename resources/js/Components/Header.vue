<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Icon from '@/Components/Icon.vue';

defineProps<{
    /** Estado da sidebar recolhível (abaixo de lg). */
    menuOpen: boolean;
}>();

defineEmits<{ (e: 'toggle-menu'): void }>();

const user = computed(() => usePage().props.auth.user);

const initials = computed(() => {
    const parts = user.value.name.trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
});
</script>

<template>
    <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-neutral-200 bg-white px-4 sm:px-6">
        <button
            type="button"
            class="-ms-2 flex h-10 w-10 items-center justify-center rounded-md text-neutral-600 transition-colors duration-150 hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 lg:hidden"
            aria-controls="app-sidebar"
            :aria-expanded="menuOpen"
            :aria-label="menuOpen ? 'Fechar menu de navegação' : 'Abrir menu de navegação'"
            @click="$emit('toggle-menu')"
        >
            <Icon :name="menuOpen ? 'x' : 'bars'" :size="24" />
        </button>

        <div class="min-w-0 flex-1 truncate text-base font-medium text-neutral-900">
            <slot name="title" />
        </div>

        <Dropdown align="right" width="48">
            <template #trigger>
                <button
                    type="button"
                    class="flex h-10 items-center gap-2 rounded-md px-2 text-sm font-medium text-neutral-700 transition-colors duration-150 hover:bg-neutral-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    aria-haspopup="menu"
                    aria-label="Menu do usuário"
                >
                    <span
                        class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-800"
                        aria-hidden="true"
                    >
                        {{ initials }}
                    </span>
                    <span class="hidden max-w-[10rem] truncate sm:block">{{ user.name }}</span>
                    <Icon name="chevron-down" :size="16" />
                </button>
            </template>

            <template #content>
                <DropdownLink :href="route('profile.edit')">Perfil</DropdownLink>
                <DropdownLink :href="route('logout')" method="post" as="button">Log Out</DropdownLink>
            </template>
        </Dropdown>
    </header>
</template>
