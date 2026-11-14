<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
defineProps<{
    section: string;
    legal: {
        entity?: string;
        tax_id?: string;
        support_email?: string;
        privacy_email?: string;
    };
}>();
</script>
<template>
    <Head :title="section" />
    <main class="legal">
        <p v-if="!legal.entity || !legal.support_email || !legal.privacy_email">
            <strong
                >Rascunho: identificação e contatos do responsável
                pendentes.</strong
            >
        </p>
        <p v-if="legal.entity">
            Responsável: {{ legal.entity }}
            <span v-if="legal.tax_id">— {{ legal.tax_id }}</span>
        </p>
        <template v-if="section === 'privacidade'"
            ><h1>Política de privacidade</h1>
            <p>
                O chAIt trata dados de cadastro, contatos, informações de
                atendimento, mensagens e anexos para oferecer seus serviços. O
                atendimento usa a UAZAPI e, quando configurada pelo cliente, a
                OpenAI para recursos de inteligência artificial.
            </p>
            <p>
                O sistema mantém dados operacionais e prévias de mensagens; o
                histórico de mensagens é consultado junto à UAZAPI. A retenção e
                a exclusão de dados locais e de terceiros dependem de análise
                operacional e obrigações aplicáveis.
            </p>
            <p>
                Você pode
                <a href="/exclusao-de-conta">solicitar a exclusão da conta</a>.
                A solicitação bloqueia o acesso e será analisada pela
                administração.
            </p>
            <p v-if="legal.privacy_email">
                Privacidade:
                <a :href="`mailto:${legal.privacy_email}`">{{
                    legal.privacy_email
                }}</a>
            </p></template
        ><template v-else-if="section === 'termos'"
            ><h1>Termos de uso</h1>
            <p>
                O chAIt oferece ferramentas de atendimento e automação para
                equipes. O usuário é responsável por suas credenciais e pelo uso
                adequado dos dados tratados no serviço.
            </p>
            <p>
                O uso de integrações de terceiros depende das condições desses
                fornecedores. O acesso pode ser bloqueado após solicitação de
                exclusão.
            </p>
            <p>
                Consulte também a
                <a href="/privacidade">política de privacidade</a>.
            </p></template
        ><template v-else
            ><h1>Suporte</h1>
            <p v-if="legal.support_email">
                Entre em contato:
                <a :href="`mailto:${legal.support_email}`">{{
                    legal.support_email
                }}</a>
            </p>
            <p v-else>Contato de suporte pendente de configuração.</p>
            <a href="/exclusao-de-conta"
                >Solicitar exclusão de conta</a
            ></template
        >
        <nav>
            <a href="/login">Entrar</a> ·
            <a href="/privacidade">Privacidade</a> ·
            <a href="/termos">Termos</a> · <a href="/suporte">Suporte</a>
        </nav>
    </main>
</template>
<style scoped>
.legal {
    max-width: 760px;
    margin: auto;
    padding: 40px 24px;
    line-height: 1.6;
}
.legal nav {
    margin-top: 36px;
}
</style>
