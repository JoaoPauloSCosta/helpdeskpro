<script setup lang="ts">
import Icon from '@/Components/Icon.vue';

defineProps<{
    label: string;
    direction?: 'asc' | 'desc' | null;
}>();

defineEmits<{ sort: [] }>();
</script>

<template>
    <th
        scope="col"
        :aria-sort="direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none'"
    >
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded uppercase tracking-wide focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            @click="$emit('sort')"
        >
            {{ label }}
            <Icon
                v-if="direction"
                :name="direction === 'asc' ? 'arrow-up' : 'arrow-down'"
                :size="16"
                class="text-primary-600"
            />
        </button>
    </th>
</template>
