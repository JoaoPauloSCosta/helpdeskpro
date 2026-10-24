<script setup lang="ts">
import Icon from '@/Components/Icon.vue';
import { formatSize, type TicketAttachment } from '@/lib/attachments';

defineProps<{
    /** Número público do chamado (parte do endereço de download). */
    ticket: string;
    attachments: TicketAttachment[];
}>();

/** Rótulo curto do tipo, a partir da extensão do nome. */
const kind = (name: string) => (name.includes('.') ? name.split('.').pop()!.toUpperCase() : 'Arquivo');
</script>

<template>
    <ul v-if="attachments.length" class="mt-3 space-y-1" aria-label="Anexos">
        <li v-for="attachment in attachments" :key="attachment.id">
            <!-- Download autorizado pelo servidor a cada acesso; o endereço nunca é um link público permanente. -->
            <a
                :href="route('tickets.attachments.show', { ticket, attachment: attachment.id })"
                class="inline-flex max-w-full items-center gap-2 rounded-md text-sm text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            >
                <Icon name="download" :size="16" class="shrink-0" />
                <span class="min-w-0 break-words">{{ attachment.name }}</span>
                <span class="shrink-0 text-xs text-neutral-500">{{ kind(attachment.name) }} · {{ formatSize(attachment.size) }}</span>
            </a>
        </li>
    </ul>
</template>
