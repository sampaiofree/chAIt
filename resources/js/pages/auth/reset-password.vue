<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
import { Head, useForm, usePage } from '@inertiajs/vue3';
const props = usePage<any>().props;
const form = useForm({
    token: props.token,
    email: props.email || '',
    password: '',
    password_confirmation: '',
});
</script>
<template>
    <Head title="Redefinir senha" />
    <main class="auth-page">
        <form class="auth-card" @submit.prevent="form.post('/reset-password')">
            <h1>Defina uma nova senha</h1>
            <label
                >E-mail<Input
                    v-model="form.email"
                    type="email"
                    required /></label
            ><label
                >Nova senha<Input
                    v-model="form.password"
                    type="password"
                    required /></label
            ><label
                >Confirmar senha<Input
                    v-model="form.password_confirmation"
                    type="password"
                    required /></label
            ><AppButton type="submit">Salvar senha</AppButton>
        </form>
    </main>
</template>
<style scoped>
.auth-page {
    min-height: 100vh;
    background: var(--background);
    display: grid;
    place-items: center;
    font-family: var(--font-sans);
}
.auth-card {
    display: grid;
    gap: var(--form-field-gap);
    width: min(90%, 400px);
    background: var(--card);
    padding: 32px;
    border: 1px solid var(--border);
    border-radius: 16px;
}
.auth-card label {
    display: grid;
    gap: var(--form-label-gap);
    font-size: 14px;
}
.auth-card > [data-slot='button'][type='submit'] {
    margin-top: calc(var(--form-actions-space) - var(--form-field-gap));
}
</style>
