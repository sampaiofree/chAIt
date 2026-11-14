<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
const form = useForm({ code: '' });
const resend = useForm({ code: '' });
const page = usePage<any>();
</script>
<template>
    <Head title="Confirme seu e-mail" />
    <main class="card">
        <h1>Confirme seu e-mail</h1>
        <p>Digite o código de seis dígitos enviado para seu e-mail.</p>
        <p v-if="page.props.flash?.status">{{ page.props.flash.status }}</p>
        <form @submit.prevent="form.post('/register/confirm')">
            <label
                >Código
                <input
                    v-model="form.code"
                    inputmode="numeric"
                    maxlength="6"
                    required
            /></label>
            <p v-if="form.errors.code">{{ form.errors.code }}</p>
            <button :disabled="form.processing">Confirmar cadastro</button>
        </form>
        <form @submit.prevent="resend.post('/register/resend')">
            <button :disabled="resend.processing">Reenviar código</button>
            <p v-if="resend.errors.code">{{ resend.errors.code }}</p>
        </form>
        <a href="/register">Reiniciar cadastro</a>
    </main>
</template>
<style scoped>
.card {
    max-width: 420px;
    margin: 10vh auto;
    padding: 32px;
    display: grid;
    gap: 16px;
}
form {
    display: grid;
    gap: var(--form-field-gap);
}
form label {
    display: grid;
    gap: var(--form-label-gap);
}
form > button {
    margin-top: calc(var(--form-actions-space) - var(--form-field-gap));
}
form > p {
    margin: 0;
}
input,
button {
    padding: 10px;
    border: 1px solid;
    border-radius: 6px;
}
</style>
