<script setup lang="ts">
import { ref } from 'vue';
import Icon, { type IconName } from '@/Components/Icon.vue';
import TextInput from '@/Components/TextInput.vue';

/** TextInput com ícone à esquerda. Atributos (id, type, required...) vão direto para o input. */
defineOptions({ inheritAttrs: false });

defineProps<{
    icon: IconName;
    invalid?: boolean;
}>();

const model = defineModel<string>({ required: true });

const input = ref<InstanceType<typeof TextInput> | null>(null);

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative">
        <Icon
            :name="icon"
            :size="20"
            class="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2"
            :class="invalid ? 'text-danger' : 'text-neutral-400'"
        />
        <TextInput
            ref="input"
            v-bind="$attrs"
            v-model="model"
            :invalid="invalid"
            class="ps-10"
        />
    </div>
</template>
