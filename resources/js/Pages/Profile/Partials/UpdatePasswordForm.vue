<script setup lang="ts">
import { nextTick, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Icon from '@/Components/Icon.vue';
import { useToast } from '@/composables/useToast';
import { useForm } from '@inertiajs/vue3';
import PasswordField from './PasswordField.vue';

const toast = useToast();
const open = ref(false);

const currentInput = ref<InstanceType<typeof PasswordField> | null>(null);
const newInput = ref<InstanceType<typeof PasswordField> | null>(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const show = async () => {
    open.value = true;
    await nextTick();
    currentInput.value?.focus();
};

const close = () => {
    form.reset();
    form.clearErrors();
    open.value = false;
};

const submit = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
            toast.success('Senha alterada com sucesso.');
        },
        onError: (errors) => {
            if (errors.password) {
                form.reset('password', 'password_confirmation');
                newInput.value?.focus();
            } else if (errors.current_password) {
                form.reset('current_password');
                currentInput.value?.focus();
            } else {
                toast.error('Não foi possível alterar a senha. Tente novamente.');
            }
        },
    });
};
</script>

<template>
    <Card title="Segurança da conta">
        <p class="-mt-1 mb-4 text-sm text-neutral-600">Mantenha seus dados de acesso protegidos.</p>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-neutral-100 text-neutral-600">
                    <Icon name="lock" />
                </span>
                <div>
                    <h3 class="text-base font-medium text-neutral-900">Senha</h3>
                    <p class="text-sm text-neutral-600">Altere sua senha de acesso.</p>
                </div>
            </div>
            <Button v-if="!open" variant="secondary" @click="show">Alterar senha</Button>
        </div>

        <form
            v-if="open"
            class="mt-6 space-y-4 border-t border-neutral-200 pt-6"
            novalidate
            @submit.prevent="submit"
        >
            <PasswordField
                ref="currentInput"
                v-model="form.current_password"
                label="Senha atual"
                autocomplete="current-password"
                :error="form.errors.current_password"
            />
            <PasswordField
                ref="newInput"
                v-model="form.password"
                label="Nova senha"
                autocomplete="new-password"
                :error="form.errors.password"
            />
            <PasswordField
                v-model="form.password_confirmation"
                label="Confirmação da nova senha"
                autocomplete="new-password"
                :error="form.errors.password_confirmation"
            />

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" :disabled="form.processing" @click="close">Cancelar</Button>
                <Button type="submit" :loading="form.processing">Salvar nova senha</Button>
            </div>
        </form>
    </Card>
</template>
