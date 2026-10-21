<script setup lang="ts">
import Icon from '@/Components/Icon.vue';
import { inputClasses } from '@/lib/ui';

const model = defineModel<string>({ required: true });
</script>

<template>
    <div class="relative">
        <Icon
            name="search"
            :size="16"
            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400"
        />
        <input
            type="search"
            class="h-10 ps-9"
            :class="inputClasses()"
            v-model="model"
        />
    </div>
</template>
