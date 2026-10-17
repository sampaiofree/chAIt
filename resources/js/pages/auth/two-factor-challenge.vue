<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
import { Head, useForm } from '@inertiajs/vue3';
const form = useForm({ code: '', recovery_code: '' });
</script>
<template>
    <Head title="Verificação em duas etapas" />
    <main class="auth-page">
        <form
            class="auth-card"
            @submit.prevent="form.post('/two-factor-challenge')"
        >
            <h1>Verificação em duas etapas</h1>
            <label
                >Código de autenticação<Input
                    v-model="form.code"
                    autocomplete="one-time-code" /></label
            ><label
                >Código de recuperação<Input
                    v-model="form.recovery_code" /></label
            ><AppButton type="submit">Continuar</AppButton>
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
