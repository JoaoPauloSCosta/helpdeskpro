<script setup lang="ts">
import PageHeader from '@/Components/PageHeader.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Visão geral" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <PageHeader title="Visão geral" />

            <div class="rounded-lg border border-neutral-200 bg-white p-4 text-sm text-neutral-900 shadow-sm sm:p-6">
                You're logged in!
            </div>
        </div>
    </AuthenticatedLayout>
</template>
