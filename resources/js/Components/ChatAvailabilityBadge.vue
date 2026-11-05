<script setup lang="ts">
import Badge from '@/Components/Badge.vue';
import { availabilityTone, type AgentState } from '@/lib/chat';

/** Disponibilidade efetiva do técnico (disponível, ocupado ou indisponível), calculada no servidor. */
defineProps<{ availability: AgentState['availability'] }>();
</script>

<template>
    <Badge :tone="availabilityTone[availability.value]">{{ availability.label }}</Badge>
</template>
