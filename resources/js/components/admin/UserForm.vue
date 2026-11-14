<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    user?: {
        id: number;
        name: string;
        email: string;
        phone: string | null;
        is_system_admin: boolean;
    };
    filters: { filter: string; search: string };
}>();
const emit = defineEmits<{ cancel: []; saved: [] }>();
const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    phone: props.user?.phone ?? '',
    password: '',
    password_confirmation: '',
});
function submit() {
    const query = new URLSearchParams(props.filters).toString();
    const options = {
        onSuccess: () => {
            form.reset();
            emit('saved');
        },
    };
    if (props.user)
        form.patch(`/admin/usuarios/${props.user.id}?${query}`, options);
    else form.post(`/admin/usuarios?${query}`, options);
}
</script>
<template>
    <form class="user-form" @submit.prevent="submit">
        <p v-if="user" class="form-help">
            Os dados da conta são compartilhados entre todos os workspaces do
            usuário.
        </p>
        <fieldset class="form-fields" :disabled="form.processing">
            <div class="form-field">
                <label for="admin-name">Nome</label>
                <Input
                    id="admin-name"
                    v-model="form.name"
                    required
                    autocomplete="name"
                    :aria-invalid="!!form.errors.name"
                    aria-describedby="admin-name-error"
                />
                <small
                    v-if="form.errors.name"
                    id="admin-name-error"
                    class="form-error"
                    role="alert"
                    >{{ form.errors.name }}</small
                >
            </div>
            <div class="form-field">
                <label for="admin-email">E-mail</label>
                <Input
                    id="admin-email"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="email"
                    :readonly="user?.is_system_admin"
                    :aria-invalid="!!form.errors.email"
                    aria-describedby="admin-email-error"
                />
                <small
                    v-if="form.errors.email"
                    id="admin-email-error"
                    class="form-error"
                    role="alert"
                    >{{ form.errors.email }}</small
                >
            </div>
            <div class="form-field">
                <label for="admin-phone">WhatsApp</label>
                <PhoneInput
                    id="admin-phone"
                    v-model="form.phone"
                    required
                    :aria-invalid="!!form.errors.phone"
                    aria-describedby="admin-phone-help admin-phone-error"
                />
                <small id="admin-phone-help" class="form-help"
                    >Informe DDI e número, somente dígitos.</small
                >
                <small
                    v-if="form.errors.phone"
                    id="admin-phone-error"
                    class="form-error"
                    role="alert"
                    >{{ form.errors.phone }}</small
                >
            </div>
            <div class="form-field">
                <label for="admin-password">{{
                    user ? 'Nova senha (opcional)' : 'Senha inicial'
                }}</label>
                <Input
                    id="admin-password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    :required="!user"
                    :aria-invalid="!!form.errors.password"
                    aria-describedby="admin-password-error"
                />
                <small
                    v-if="form.errors.password"
                    id="admin-password-error"
                    class="form-error"
                    role="alert"
                    >{{ form.errors.password }}</small
                >
            </div>
            <div class="form-field">
                <label for="admin-confirm">Confirmar senha</label>
                <Input
                    id="admin-confirm"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    :required="!user || !!form.password"
                />
            </div>
        </fieldset>
        <div class="form-actions">
            <AppButton
                variant="secondary"
                :disabled="form.processing"
                @click="emit('cancel')"
                >Cancelar</AppButton
            >
            <AppButton type="submit" :loading="form.processing">{{
                user ? 'Salvar alterações' : 'Cadastrar usuário'
            }}</AppButton>
        </div>
    </form>
</template>
<style scoped>
.user-form {
    display: grid;
    gap: 16px;
}
.form-fields {
    display: grid;
    gap: var(--form-field-gap);
    border: 0;
    padding: 0;
}
.form-field {
    display: grid;
    gap: var(--form-label-gap);
}
.form-field label {
    font-size: 14px;
    font-weight: 500;
}
.form-help {
    color: var(--muted-foreground);
    font-size: 13px;
}
.form-error {
    color: var(--danger);
    font-size: 13px;
}
.form-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--form-button-gap);
    margin-top: calc(var(--form-actions-space) - var(--form-field-gap));
}
</style>
