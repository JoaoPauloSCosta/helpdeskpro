<script setup lang="ts">
defineProps<{
    tabs: { key: string; label: string; count?: number }[];
}>();

const active = defineModel<string>({ required: true });
</script>

<template>
    <div class="border-b border-neutral-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto" role="tablist">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="active === tab.key"
                class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-1 pb-3 pt-1 text-sm font-medium transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                :class="
                    active === tab.key
                        ? 'border-primary-500 text-primary-600'
                        : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700'
                "
                @click="active = tab.key"
            >
                {{ tab.label }}
                <span
                    v-if="tab.count !== undefined"
                    class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600"
                >
                    {{ tab.count }}
                </span>
            </button>
        </nav>
    </div>
</template>
