<script setup lang="ts">
import { watch } from 'vue';
import { useToast } from '@/composables/useToast';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const toast = useToast();

// Confirmação do reenvio do link de verificação, por toast.
watch(
    () => props.status,
    (status) => {
        if (status === 'verification-link-sent') toast.success('Um novo link de verificação foi enviado ao e-mail informado no cadastro.');
    },
    { immediate: true },
);
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />

        <div class="mb-4 text-sm text-neutral-600">
            Thanks for signing up! Before getting started, could you verify your
            email address by clicking on the link we just emailed to you? If you
            didn't receive the email, we will gladly send you another.
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Resend Verification Email
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-neutral-600 underline hover:text-neutral-900 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                    >Log Out</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
