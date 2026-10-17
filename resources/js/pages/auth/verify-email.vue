<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import { Head, usePage } from '@inertiajs/vue3';
const page = usePage<any>();
</script>
<template>
    <Head title="Verifique seu e-mail" />
    <main class="auth-page">
        <section class="auth-card">
            <h1>Verifique seu e-mail</h1>
            <p>Enviamos um link de verificação para o endereço da sua conta.</p>
            <form method="post" action="/email/verification-notification">
                <input
                    type="hidden"
                    name="_token"
                    :value="page.props.csrf_token"
                /><AppButton type="submit">Reenviar link</AppButton>
            </form>
            <form method="post" action="/logout">
                <input
                    type="hidden"
                    name="_token"
                    :value="page.props.csrf_token"
                /><AppButton type="submit" variant="secondary">Sair</AppButton>
            </form>
        </section>
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
.auth-card p {
    color: var(--muted-foreground);
    font-size: 13px;
}
.auth-card .secondary {
    background: var(--card);
    border: 1px solid var(--border);
    color: var(--brand-strong);
}
.auth-card > [data-slot='button'][type='submit'] {
    margin-top: calc(var(--form-actions-space) - var(--form-field-gap));
}
</style>
