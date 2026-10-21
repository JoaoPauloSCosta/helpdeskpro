<script setup lang="ts">
import { computed } from 'vue';
import Badge from '@/Components/Badge.vue';
import Card from '@/Components/Card.vue';
import Icon from '@/Components/Icon.vue';
import { usePage } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
}>();

const user = computed(() => usePage().props.auth.user);

const initials = computed(() => {
    const parts = user.value.name.trim().split(/\s+/).filter(Boolean);
    const first = parts[0]?.[0] ?? '';
    const last = parts.length > 1 ? parts[parts.length - 1][0] : '';
    return (first + last).toUpperCase();
});
</script>

<template>
    <Card>
        <div class="flex flex-col items-center text-center">
            <div
                class="flex h-20 w-20 items-center justify-center rounded-full bg-primary-100 text-2xl font-semibold text-primary-800"
                aria-hidden="true"
            >
                {{ initials }}
            </div>
            <h2 class="mt-4 max-w-full break-words text-lg font-semibold text-neutral-900">
                {{ user.name }}
            </h2>
            <p class="mt-1 flex max-w-full items-center gap-2 text-sm text-neutral-600">
                <Icon name="envelope" :size="16" />
                <span class="min-w-0 break-all">{{ user.email }}</span>
            </p>
            <div v-if="mustVerifyEmail" class="mt-4">
                <Badge v-if="user.email_verified_at" tone="success">E-mail verificado</Badge>
                <Badge v-else tone="warning">E-mail não verificado</Badge>
            </div>
        </div>
    </Card>
</template>
