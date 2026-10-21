<script setup lang="ts">
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { IconName } from '@/Components/Icon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps<{ status: number }>();

/** Mensagens neutras, sem detalhes internos (design-system.md, seção 6.5). */
const messages: Record<number, { title: string; description: string; icon: IconName }> = {
    403: {
        title: 'Acesso negado',
        description: 'Você não tem permissão para esta ação.',
        icon: 'lock',
    },
    404: {
        title: 'Página não encontrada',
        description: 'O endereço acessado não existe ou não está disponível.',
        icon: 'info',
    },
    500: {
        title: 'Não foi possível carregar a página',
        description: 'Ocorreu uma falha no servidor. Tente novamente em instantes.',
        icon: 'warning',
    },
    503: {
        title: 'Sistema em manutenção',
        description: 'O serviço está temporariamente indisponível. Tente novamente em instantes.',
        icon: 'clock',
    },
};

const message = computed(() => messages[props.status] ?? messages[500]);

// Em erros fora das rotas autenticadas os dados compartilhados podem não existir.
const layout = computed(() => (usePage().props.auth?.user ? AuthenticatedLayout : GuestLayout));
</script>

<template>
    <Head :title="message.title" />

    <component :is="layout">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-neutral-200 bg-white shadow-sm">
                <EmptyState :title="message.title" :description="message.description" :icon="message.icon">
                    <Button variant="secondary" :href="route('home')">Voltar ao início</Button>
                </EmptyState>
            </div>
        </div>
    </component>
</template>
