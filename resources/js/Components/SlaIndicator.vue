<script setup lang="ts">
import { computed } from 'vue';
import Badge from '@/Components/Badge.vue';
import Icon, { type IconName } from '@/Components/Icon.vue';
import type { Tone } from '@/lib/ui';
import type { SlaState } from '@/lib/ticket';

const props = defineProps<{
    state: SlaState;
    /** Texto temporal, ex.: "Vence em 1h 20min". Substitui o texto padrão do estado. */
    label?: string;
}>();

const config: Record<SlaState, { tone: Tone; icon: IconName; text: string }> = {
    on_track: { tone: 'success', icon: 'clock', text: 'No prazo' },
    at_risk: { tone: 'warning', icon: 'warning', text: 'Em risco' },
    overdue: { tone: 'danger', icon: 'x-circle', text: 'Vencido' },
    paused: { tone: 'neutral', icon: 'pause', text: 'Pausado' },
    met: { tone: 'success', icon: 'check-circle', text: 'Cumprido' },
};

const current = computed(() => config[props.state]);
</script>

<template>
    <Badge :tone="current.tone">
        <Icon :name="current.icon" :size="16" />
        {{ label ?? current.text }}
    </Badge>
</template>
