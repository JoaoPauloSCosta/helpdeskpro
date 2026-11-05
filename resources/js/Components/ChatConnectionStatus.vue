<script setup lang="ts">
import { computed } from 'vue';
import Badge from '@/Components/Badge.vue';
import type { Tone } from '@/lib/ui';

/** Indicador de atualização em tempo real (CHAT-02/03). Texto sempre junto da cor. */
const props = defineProps<{ status: 'connected' | 'disconnected' | 'connecting' | 'reconnecting' | 'failed' }>();

const view = computed<{ label: string; tone: Tone }>(() => {
    switch (props.status) {
        case 'connected':
            return { label: 'Conectado em tempo real', tone: 'success' };
        case 'connecting':
            return { label: 'Conectando…', tone: 'info' };
        case 'reconnecting':
            return { label: 'Reconectando…', tone: 'warning' };
        default:
            return { label: 'Sem conexão em tempo real. Atualizando a cada poucos segundos.', tone: 'warning' };
    }
});
</script>

<template>
    <Badge :tone="view.tone" role="status">
        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ view.label }}
    </Badge>
</template>
