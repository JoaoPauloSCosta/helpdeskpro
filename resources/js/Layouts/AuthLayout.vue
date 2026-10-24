<script setup lang="ts">
import BrandMark from '@/Components/BrandMark.vue';
import Icon from '@/Components/Icon.vue';
import ToastContainer from '@/Components/ToastContainer.vue';

defineProps<{
    /** Aviso exibido abaixo do formulário, ao lado do cadeado. */
    securityNote?: string;
}>();
</script>

<template>
    <div class="grid min-h-screen bg-surface md:grid-cols-[1fr_0.92fr]">
        <section
            class="relative isolate hidden flex-col items-center justify-center overflow-hidden bg-primary-600 px-14 py-16 text-center text-white md:flex"
            aria-label="Apresentação do Help Desk Pro"
        >
            <img
                src="/images/auth-panel.jpg"
                alt=""
                class="absolute inset-0 -z-20 h-full w-full scale-[1.03] object-cover"
            />
            <div
                class="absolute inset-0 -z-10 bg-gradient-to-br from-primary-700/[.91] to-primary-500/[.88]"
                aria-hidden="true"
            />
            <div
                class="absolute left-7 top-7 h-14 w-20 bg-[radial-gradient(rgba(255,255,255,.8)_2px,transparent_2.5px)] bg-[length:15px_15px] opacity-45"
                aria-hidden="true"
            />

            <div class="w-full max-w-sm">
                <img
                    src="/images/logo-light.png"
                    alt="Help Desk Pro"
                    class="mx-auto mb-10 h-auto w-64"
                />
                
                <h2 class="mb-3.5 text-3xl font-bold leading-tight tracking-tight lg:text-4xl">
                    Seu suporte mais organizado.
                </h2>
                <p class="mx-auto max-w-xs text-[15px] leading-relaxed text-white/85">
                    Centralize solicitações, acompanhe chamados e resolva problemas com mais
                    agilidade — tudo em um só lugar.
                </p>
            </div>

            <p class="absolute inset-x-7 bottom-6 text-xs tracking-wide text-white/70">
                HELP DESK PRO &nbsp;·&nbsp; Plataforma de atendimento e suporte técnico
            </p>
        </section>

        <main class="flex items-center justify-center px-6 py-10 sm:px-12 lg:px-16">
            <div class="w-full max-w-md md:max-w-sm">
                <BrandMark class="mb-10 md:hidden" />

                <slot />

                <p
                    v-if="securityNote"
                    class="mt-8 flex items-center justify-center gap-2 text-xs text-neutral-500"
                >
                    <Icon name="lock" :size="16" />
                    {{ securityNote }}
                </p>
            </div>
        </main>

        <ToastContainer />
    </div>
</template>
