<script setup lang="ts">
import Badge from '@/Components/Badge.vue';
import { statusTone, type TicketStatus } from '@/lib/ticket';

defineProps<{ status: TicketStatus }>();
</script>

<template>
    <Badge :tone="statusTone[status]">{{ status }}</Badge>
</template>
