<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
import { Head, useForm } from '@inertiajs/vue3';
const form = useForm({ password: '' });
</script>
<template>
    <Head title="Confirmar senha" />
    <main class="auth-page">
        <form
            class="auth-card"
            @submit.prevent="form.post('/user/confirm-password')"
        >
            <h1>Confirme sua senha</h1>
            <p>Para continuar, confirme a senha da sua conta.</p>
            <div class="form-field">
                <label for="confirm-password">Senha</label
                ><Input
                    id="confirm-password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                />
                <p v-if="form.errors.password" class="form-error" role="alert">
                    {{ form.errors.password }}
                </p>
            </div>
            <AppButton type="submit">Confirmar</AppButton>
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
.auth-card > [data-slot='button'][type='submit'] {
    margin-top: calc(var(--form-actions-space) - var(--form-field-gap));
}
</style>
