<script setup lang="ts">
import AppBrand from '@/components/AppBrand.vue';
import { Head } from '@inertiajs/vue3';
import { ArrowUpRight, Mail } from '@lucide/vue';
</script>

<template>
    <Head title="Suporte CHAIT">
        <meta
            name="description"
            content="Precisa de ajuda com o CHAIT? Entre em contato pelo e-mail suporte@chait.com."
        />
    </Head>
    <main class="support-page">
        <article class="support-content" aria-labelledby="support-title">
            <div class="support-brand"><AppBrand /></div>
            <header>
                <p class="eyebrow">Suporte CHAIT</p>
                <h1 id="support-title">Como podemos ajudar?</h1>
                <p class="introduction">
                    Se você tem uma dúvida ou encontrou alguma dificuldade com o
                    CHAIT, fale com a gente.
                </p>
            </header>
            <a class="contact-card" href="mailto:suporte@chait.com">
                <span class="mail-icon"
                    ><Mail :size="24" aria-hidden="true"
                /></span>
                <span class="contact-text"
                    ><span class="contact-label">Envie um e-mail</span
                    ><span class="contact-email">suporte@chait.com</span></span
                >
                <ArrowUpRight
                    :size="22"
                    aria-hidden="true"
                    class="contact-arrow"
                />
            </a>
            <section class="guidance" aria-labelledby="guidance-title">
                <h2 id="guidance-title">Para facilitar o atendimento</h2>
                <p>Ao escrever, conte um pouco sobre o que aconteceu:</p>
                <ul>
                    <li>Descreva sua dúvida ou o problema encontrado.</li>
                    <li>
                        Explique o que você estava tentando fazer e o resultado
                        esperado.
                    </li>
                    <li>
                        Se ajudar, inclua uma captura de tela com os dados
                        sensíveis ocultos.
                    </li>
                </ul>
                <p class="privacy-note">
                    Para sua segurança, não envie senhas, tokens ou códigos de
                    acesso.
                </p>
            </section>
            <footer>CHAIT · Estamos aqui para ajudar.</footer>
        </article>
    </main>
</template>

<style scoped>
.support-page {
    min-height: 100dvh;
    display: grid;
    place-items: center;
    padding: 64px 24px;
    background: var(--background);
    color: var(--foreground);
    font-family: var(--font-sans);
}
.support-content {
    width: min(100%, 640px);
}
.support-brand {
    margin-bottom: 56px;
}
.eyebrow {
    margin: 0 0 12px;
    color: var(--muted-foreground);
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}
h1 {
    margin: 0;
    font-size: clamp(30px, 5vw, 44px);
    font-weight: 600;
    letter-spacing: -0.04em;
    line-height: 1.15;
}
.introduction {
    margin: 20px 0 32px;
    max-width: 520px;
    color: var(--muted-foreground);
    font-size: 17px;
    line-height: 1.7;
}
.contact-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 24px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--card);
    color: var(--foreground);
    text-decoration: none;
}
.contact-card:hover {
    border-color: var(--ring);
}
.contact-card:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 4px;
}
.mail-icon {
    display: grid;
    flex: none;
    place-items: center;
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--accent);
    color: var(--accent-foreground);
}
.contact-text {
    display: grid;
    gap: 4px;
    min-width: 0;
}
.contact-label {
    color: var(--muted-foreground);
    font-size: 13px;
}
.contact-email {
    font-size: clamp(18px, 4vw, 24px);
    font-weight: 600;
    overflow-wrap: anywhere;
}
.contact-arrow {
    margin-left: auto;
    flex: none;
    color: var(--muted-foreground);
}
.guidance {
    margin-top: 36px;
    font-size: 14px;
    line-height: 1.8;
    color: var(--muted-foreground);
}
h2 {
    margin: 0 0 8px;
    font-size: 16px;
    font-weight: 600;
    color: var(--foreground);
}
.guidance p {
    margin: 0;
}
ul {
    margin: 12px 0 20px;
    padding-left: 20px;
    list-style: disc;
}
li + li {
    margin-top: 6px;
}
.privacy-note {
    padding-top: 20px;
    border-top: 1px solid var(--border);
}
footer {
    margin-top: 48px;
    color: var(--muted-foreground);
    font-size: 12px;
}
@media (max-width: 480px) {
    .support-page {
        padding: 36px 20px;
    }
    .support-brand {
        margin-bottom: 36px;
    }
    .contact-card {
        gap: 12px;
        padding: 18px 14px;
    }
    .mail-icon {
        width: 40px;
        height: 40px;
    }
}
</style>
