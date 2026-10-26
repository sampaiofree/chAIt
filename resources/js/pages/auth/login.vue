<script setup lang="ts">
import AppBrand from '@/components/AppBrand.vue';
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
const props = defineProps<{
    canResetPassword?: boolean;
    canRegister?: boolean;
    status?: string;
    errors?: Record<string, string>;
}>();
const form = useForm({ email: '', password: '', remember: false });
const submit = () => form.post('/login');
const error = computed(() => form.errors.email || form.errors.password);
</script>
<template>
    <Head title="Entrar" />
    <main class="auth-page">
        <form class="auth-card" @submit.prevent="submit">
            <a href="/" class="auth-brand"><AppBrand /></a>
            <h1>Bem-vindo de volta</h1>
            <p>Acesse seu workspace para continuar os atendimentos.</p>
            <div v-if="status" class="success">{{ status }}</div>
            <label
                >E-mail<Input
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    autofocus /></label
            ><label
                >Senha<Input
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required /></label
            ><label class="remember"
                ><input v-model="form.remember" type="checkbox" /> Manter
                conectado</label
            >
            <div v-if="error" class="error">{{ error }}</div>
            <AppButton type="submit" :disabled="form.processing">{{
                form.processing ? 'Entrando…' : 'Entrar'
            }}</AppButton
            ><Link v-if="canResetPassword" href="/forgot-password"
                >Esqueci minha senha</Link
            ><small v-if="canRegister"
                >Não tem conta? <Link href="/register">Criar conta</Link></small
            >
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
    color: var(--foreground);
}
.auth-card p {
    text-align: center;
    margin: 0 0 8px;
    font-size: 13px;
    color: var(--muted-foreground);
}
.auth-card label {
    display: grid;
    gap: var(--form-label-gap);
    color: var(--muted-foreground);
    font-size: 14px;
}
.remember {
    display: flex !important;
    align-items: center;
    gap: 8px !important;
}
.auth-card > a:not(.auth-brand),
.auth-card small {
    text-align: center;
    font-size: 14px;
    color: var(--brand-strong);
    text-decoration: none;
}
.auth-card small {
    color: var(--muted-foreground);
}
.error {
    color: var(--danger);
    font-size: 14px;
}
.success {
    color: var(--success);
    background: var(--success-soft);
    padding: 10px;
    border-radius: 8px;
    font-size: 14px;
}
.auth-card > [data-slot='button'][type='submit'] {
    margin-top: calc(var(--form-actions-space) - var(--form-field-gap));
}
</style>
