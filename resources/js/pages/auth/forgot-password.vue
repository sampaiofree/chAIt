<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
import { Head, Link, useForm } from '@inertiajs/vue3';
const form = useForm({ email: '' });
</script>
<template>
    <Head title="Recuperar senha" />
    <main class="auth-page">
        <form class="auth-card" @submit.prevent="form.post('/forgot-password')">
            <h1>Recuperar senha</h1>
            <p>Informe seu e-mail para receber as instruções.</p>
            <label
                >E-mail<Input v-model="form.email" type="email" required
            /></label>
            <div class="error">{{ form.errors.email }}</div>
            <AppButton type="submit">Enviar instruções</AppButton
            ><Link href="/login">Voltar ao login</Link>
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
.auth-card h1 {
    margin: 0;
}
.auth-card p,
.auth-card a {
    font-size: 13px;
    color: var(--muted-foreground);
}
.auth-card label {
    display: grid;
    gap: var(--form-label-gap);
    font-size: 14px;
}
.error {
    color: var(--danger);
}
.auth-card > [data-slot='button'][type='submit'] {
    margin-top: calc(var(--form-actions-space) - var(--form-field-gap));
}
</style>
