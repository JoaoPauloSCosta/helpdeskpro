<script setup lang="ts">
withDefaults(defineProps<{ size?: 16 | 20 | 24 }>(), { size: 16 });
</script>

<template>
    <svg
        class="animate-spin motion-reduce:animate-none"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        aria-hidden="true"
    >
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
</template>
