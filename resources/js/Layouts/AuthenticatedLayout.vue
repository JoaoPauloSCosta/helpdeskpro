<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Header from '@/Components/Header.vue';
import Sidebar from '@/Components/Sidebar.vue';
import ToastContainer from '@/Components/ToastContainer.vue';

// Abaixo de lg a sidebar é um painel recolhível; a partir de lg ela é fixa.
const menuOpen = ref(false);
const isDesktop = ref(true);
const sidebar = ref<HTMLElement | null>(null);
const toggleButton = ref<HTMLElement | null>(null);

let media: MediaQueryList | null = null;
const syncMedia = () => {
    isDesktop.value = media?.matches ?? true;
    if (isDesktop.value) menuOpen.value = false;
};

const close = (restoreFocus = true) => {
    if (!menuOpen.value) return;
    menuOpen.value = false;
    if (restoreFocus) toggleButton.value?.focus();
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') close();
};

let removeNavigateListener: (() => void) | undefined;

onMounted(() => {
    media = window.matchMedia('(min-width: 1024px)');
    syncMedia();
    media.addEventListener('change', syncMedia);
    document.addEventListener('keydown', onKeydown);
    removeNavigateListener = router.on('navigate', () => close(false));
    toggleButton.value = document.querySelector<HTMLElement>('[aria-controls="app-sidebar"]');
});

onUnmounted(() => {
    media?.removeEventListener('change', syncMedia);
    document.removeEventListener('keydown', onKeydown);
    removeNavigateListener?.();
});

// Ao abrir no mobile, leva o foco para dentro do menu.
watch(menuOpen, async (open) => {
    if (!open) return;
    await nextTick();
    sidebar.value?.querySelector<HTMLElement>('a')?.focus();
});
</script>

<template>
    <div>
        <div class="flex min-h-screen bg-app-bg">
            <!-- Fundo escurecido (apenas abaixo de lg, com o menu aberto) -->
            <Transition
                enter-active-class="transition-opacity duration-150 ease-in-out"
                enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-150 ease-in-out"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="menuOpen"
                    class="fixed inset-0 z-30 bg-neutral-900/50 lg:hidden"
                    aria-hidden="true"
                    @click="close()"
                />
            </Transition>

            <aside
                id="app-sidebar"
                ref="sidebar"
                class="fixed inset-y-0 start-0 z-40 w-64 max-w-[85vw] border-e border-neutral-200 bg-white transition-transform duration-150 ease-in-out motion-reduce:transition-none lg:sticky lg:top-0 lg:h-screen lg:max-w-none lg:shrink-0 lg:translate-x-0"
                :class="menuOpen ? 'translate-x-0' : '-translate-x-full'"
                :inert="!isDesktop && !menuOpen"
            >
                <Sidebar />
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <Header :menu-open="menuOpen" @toggle-menu="menuOpen ? close() : (menuOpen = true)">
                    <template v-if="$slots.header" #title>
                        <slot name="header" />
                    </template>
                </Header>

                <main class="min-w-0 flex-1">
                    <slot />
                </main>
            </div>
        </div>
        <ToastContainer />
    </div>
</template>
