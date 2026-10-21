<script setup lang="ts">
import { computed, useId } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps<{
    label: string;
    error?: string;
    help?: string;
    required?: boolean;
}>();

const id = useId();
const errorId = `${id}-error`;
const helpId = `${id}-help`;

const describedby = computed(
    () => [props.error && errorId, props.help && helpId].filter(Boolean).join(' ') || undefined,
);
</script>

<template>
    <div>
        <InputLabel :for="id" :value="label" :required="required" />
        <div class="mt-1">
            <slot :id="id" :describedby="describedby" :invalid="!!error" />
        </div>
        <p v-if="help" :id="helpId" class="mt-1 text-xs text-neutral-500">{{ help }}</p>
        <InputError :id="errorId" class="mt-1" :message="error" />
    </div>
</template>
