<script setup lang="ts">
import { positionMessage } from '@/lib/chat';

/** Posição individual na fila (CHAT-02). Só a do próprio solicitante; nada de dados de terceiros. */
defineProps<{ position: number }>();
</script>

<template>
    <div class="text-center">
        <p class="text-sm font-medium text-neutral-600">Sua posição na fila</p>
        <p class="mt-1 text-6xl font-semibold text-primary-600" aria-hidden="true">{{ position }}</p>
        <p class="mt-3 text-sm text-neutral-700">
            <span class="sr-only">Posição {{ position }}. </span>{{ positionMessage(position) }}
        </p>
    </div>
</template>
