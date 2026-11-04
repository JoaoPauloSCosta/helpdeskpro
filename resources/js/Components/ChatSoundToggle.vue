<script setup lang="ts">
import Button from '@/Components/Button.vue';
import Icon from '@/Components/Icon.vue';
import Switch from '@/Components/Switch.vue';

/** Preferência de som (RF-31): liga/desliga, teste e aviso quando o navegador bloqueia o áudio. */
defineProps<{ saving?: boolean; blocked?: boolean }>();

const enabled = defineModel<boolean>({ required: true });
defineEmits<{ test: [] }>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <Switch v-model="enabled" label="Som das notificações" :disabled="saving" />
        <Button variant="secondary" size="sm" @click="$emit('test')">
            <Icon :name="enabled ? 'speaker-wave' : 'speaker-x-mark'" :size="16" />
            Testar som
        </Button>
        <p v-if="blocked && enabled" class="text-xs text-warning-text" role="status">
            O navegador bloqueou o som. Clique em qualquer lugar da página para ativá-lo.
        </p>
    </div>
</template>
