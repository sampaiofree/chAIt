<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppBrand from '@/components/AppBrand.vue';
import { buttonVariants } from '@/components/ui/button';
defineProps<{ canRegister: boolean }>();
</script>
<template>
    <Head title="Atendimento pelo WhatsApp" />
    <main class="welcome">
        <div class="welcome-card">
            <AppBrand />
            <p>Atendimento humano e conversas do WhatsApp em um só lugar.</p>
            <div class="welcome-actions">
                <Link :class="buttonVariants()" href="/login">Entrar</Link
                ><Link
                    v-if="canRegister"
                    :class="buttonVariants({ variant: 'outline' })"
                    href="/register"
                    >Criar conta</Link
                >
            </div>
        </div>
    </main>
</template>
<style scoped>
.welcome {
    min-height: 100dvh;
    padding: 16px;
    display: grid;
    place-items: center;
    background: var(--background);
    font-family: var(--font-sans);
}
.welcome-card {
    max-width: 430px;
    text-align: center;
    padding: 52px 35px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: 0 16px 60px #163a2310;
}
.welcome-logo {
    font-size: 32px;
    font-weight: 800;
    color: var(--brand-strong);
}
.welcome-logo span {
    color: var(--brand-highlight);
}
.welcome p {
    color: var(--muted-foreground);
    line-height: 1.6;
    margin: 14px 0 28px;
}
.welcome-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}
</style>
