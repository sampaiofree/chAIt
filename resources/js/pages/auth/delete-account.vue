<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
const email = useForm({ email: '' });
const code = useForm({ code: '' });
const sent = ref(false);
</script>
<template>
    <Head title="Solicitar exclusão de conta" />
    <main class="card">
        <h1>Solicitar exclusão de conta</h1>
        <p>
            Após confirmar o e-mail, seu acesso será bloqueado e a administração
            analisará seu pedido. Os dados ainda não serão excluídos
            automaticamente.
        </p>
        <form
            @submit.prevent="
                email.post('/exclusao-de-conta', {
                    onSuccess: () => (sent = true),
                })
            "
        >
            <label
                >E-mail <input v-model="email.email" type="email" required
            /></label>
            <p v-if="email.errors.email">{{ email.errors.email }}</p>
            <button :disabled="email.processing">Enviar código</button>
        </form>
        <div v-if="sent">
            <p>Se houver uma conta elegível, enviaremos um código.</p>
            <form @submit.prevent="code.post('/exclusao-de-conta/confirmar')">
                <label
                    >Código
                    <input
                        v-model="code.code"
                        inputmode="numeric"
                        maxlength="6"
                        required
                /></label>
                <p v-if="code.errors.code">{{ code.errors.code }}</p>
                <button :disabled="code.processing">
                    Confirmar solicitação
                </button>
            </form>
        </div>
        <a href="/login">Voltar ao login</a>
    </main>
</template>
<style scoped>
.card {
    max-width: 500px;
    margin: 10vh auto;
    padding: 32px;
    display: grid;
    gap: 16px;
}
form {
    display: grid;
    gap: 12px;
}
input,
button {
    padding: 10px;
    border: 1px solid;
    border-radius: 6px;
}
</style>
