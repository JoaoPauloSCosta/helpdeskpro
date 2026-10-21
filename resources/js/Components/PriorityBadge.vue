<script setup lang="ts">
import Badge from '@/Components/Badge.vue';
import { priorityGlyph, priorityTone, type TicketPriority } from '@/lib/ticket';

defineProps<{ priority: TicketPriority }>();
</script>

<template>
    <Badge :tone="priorityTone[priority]">
        <span aria-hidden="true">{{ priorityGlyph[priority] }}</span>
        {{ priority }}
    </Badge>
</template>
