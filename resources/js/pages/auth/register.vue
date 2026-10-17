<script setup lang="ts">
import AppBrand from '@/components/AppBrand.vue';
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
import { Head, Link, useForm } from '@inertiajs/vue3';
const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
</script>
<template>
    <Head title="Criar conta" />
    <main class="auth-page">
        <form class="auth-card" @submit.prevent="form.post('/register')">
            <a href="/" class="auth-brand"><AppBrand /></a>
            <h1>Crie sua conta</h1>
            <label
                >Nome<Input
                    v-model="form.name"
                    autocomplete="name"
                    required /></label
            ><label
                >E-mail<Input
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required /></label
            ><label
                >Senha<Input
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required /></label
            ><label
                >Confirmar senha<Input
                    v-model="form.password_confirmation"
                    type="password"
                    required
            /></label>
            <div v-if="form.errors.email || form.errors.password" class="error">
                {{ form.errors.email || form.errors.password }}
            </div>
            <AppButton type="submit" :disabled="form.processing"
                >Criar conta</AppButton
            ><small>Já tem conta? <Link href="/login">Entrar</Link></small>
        </form>
    </main>
</template>
<style scoped>
.auth-page {
    min-height: 100dvh;
    background: var(--background);
    display: grid;
    place-items: center;
    padding: 20px;
    font-family: var(--font-sans);
}
.auth-card {
    display: grid;
    gap: var(--form-field-gap);
    width: min(100%, 400px);
    background: var(--card);
    padding: 35px;
    border: 1px solid var(--border);
    border-radius: 16px;
}
.auth-brand {
    color: var(--brand-strong);
    text-align: center;
    font-weight: 800;
    font-size: 21px;
    text-decoration: none;
}
.auth-brand span {
    color: var(--brand-highlight);
}
.auth-card h1 {
    font-size: 23px;
    margin: 3px 0;
    text-align: center;
}
.auth-card label {
    display: grid;
    gap: var(--form-label-gap);
    color: var(--muted-foreground);
    font-size: 14px;
}
.auth-card small {
    text-align: center;
    font-size: 14px;
    color: var(--muted-foreground);
}
.auth-card a {
    color: var(--brand-strong);
}
.error {
    color: var(--danger);
    font-size: 14px;
}
.auth-card > [data-slot='button'][type='submit'] {
    margin-top: calc(var(--form-actions-space) - var(--form-field-gap));
}
</style>
