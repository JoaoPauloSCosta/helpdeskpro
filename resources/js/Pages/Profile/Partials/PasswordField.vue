<script setup lang="ts">
import { ref } from 'vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps<{
    label: string;
    error?: string;
    autocomplete: string;
}>();

const model = defineModel<string>({ required: true });
const visible = ref(false);
const input = ref<InstanceType<typeof TextInput> | null>(null);

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <FormField :label="label" :error="error" required v-slot="{ id, describedby, invalid }">
        <div class="relative">
            <TextInput
                :id="id"
                ref="input"
                v-model="model"
                :type="visible ? 'text' : 'password'"
                :autocomplete="autocomplete"
                required
                class="pe-11"
                :invalid="invalid"
                :aria-describedby="describedby"
            />
            <button
                type="button"
                class="absolute end-1 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-neutral-500 hover:text-neutral-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                :aria-label="visible ? `Ocultar ${label.toLowerCase()}` : `Mostrar ${label.toLowerCase()}`"
                :aria-pressed="visible"
                @click="visible = !visible"
            >
                <Icon :name="visible ? 'eye-slash' : 'eye'" />
            </button>
        </div>
    </FormField>
</template>
