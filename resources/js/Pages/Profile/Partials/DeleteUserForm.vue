<script setup lang="ts">
import { nextTick, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const confirmingUserDeletion = ref(false);
const passwordInput = ref<InstanceType<typeof TextInput> | null>(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value?.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => {
            form.reset();
        },
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <Card title="Excluir conta">
        <p class="text-sm text-neutral-600">
            Ao excluir sua conta, todos os recursos e dados dela serão removidos permanentemente.
            Antes de continuar, guarde qualquer informação que queira manter.
        </p>

        <div class="mt-4 flex sm:justify-end">
            <Button variant="danger" class="w-full sm:w-auto" @click="confirmUserDeletion">Excluir conta</Button>
        </div>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-4 sm:p-6">
                <h2 class="text-lg font-semibold text-neutral-900">Tem certeza de que deseja excluir sua conta?</h2>

                <p class="mt-1 text-sm text-neutral-600">
                    Esta ação é permanente. Digite sua senha para confirmar a exclusão.
                </p>

                <form class="mt-6" novalidate @submit.prevent="deleteUser">
                    <FormField label="Senha" :error="form.errors.password" required v-slot="{ id, describedby, invalid }">
                        <TextInput
                            :id="id"
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            :invalid="invalid"
                            :aria-describedby="describedby"
                        />
                    </FormField>

                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button variant="secondary" :disabled="form.processing" @click="closeModal">Cancelar</Button>
                        <Button type="submit" variant="danger" :loading="form.processing">Excluir conta</Button>
                    </div>
                </form>
            </div>
        </Modal>
    </Card>
</template>
