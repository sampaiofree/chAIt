<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppBrand from '@/components/AppBrand.vue';

const props = defineProps<{
    section: string;
    legal: {
        entity?: string;
        tax_id?: string;
        support_email?: string;
        privacy_email?: string;
    };
}>();
const isPrivacy = computed(() => props.section === 'privacidade');
const title = computed(() =>
    isPrivacy.value ? 'Política de privacidade' : 'Termos de uso',
);
const contactEmail = computed(
    () => props.legal.privacy_email || props.legal.support_email,
);
const sections = [
    ['responsavel', 'Quem trata os dados'],
    ['dados', 'Dados que tratamos'],
    ['finalidades', 'Como usamos os dados'],
    ['compartilhamento', 'Integrações e compartilhamento'],
    ['inteligencia-artificial', 'Inteligência artificial'],
    ['cookies', 'Cookies e armazenamento local'],
    ['seguranca', 'Segurança'],
    ['retencao', 'Retenção e exclusão'],
    ['direitos', 'Seus direitos'],
    ['contato', 'Contato e atualizações'],
];
</script>

<template>
    <Head :title="title">
        <meta
            name="description"
            :content="
                isPrivacy
                    ? 'Saiba como o chAIt trata dados pessoais, utiliza integrações e atende solicitações de privacidade.'
                    : 'Conheça as condições de uso da plataforma chAIt.'
            "
        />
    </Head>
    <div class="legal-page">
        <a class="skip-link" href="#conteudo">Ir para o conteúdo</a>
        <header class="public-header">
            <a class="brand-link" href="/" aria-label="chAIt — página inicial"
                ><AppBrand
            /></a>
            <a class="support-link" href="/suporte">Fale com a gente</a>
        </header>
        <main id="conteudo" class="legal-content" tabindex="-1">
            <header class="document-header">
                <p class="eyebrow">Privacidade e transparência</p>
                <h1>{{ title }}</h1>
                <p v-if="isPrivacy" class="introduction">
                    Entenda quais dados passam pelo chAIt, por que são
                    utilizados e como exercer seus direitos.
                </p>
                <p v-if="isPrivacy" class="document-date">
                    Última atualização:
                    <time datetime="2026-11-14">14 de novembro de 2026</time>
                </p>
            </header>
            <div
                v-if="
                    !legal.entity ||
                    !legal.support_email ||
                    !legal.privacy_email
                "
                class="review-note"
                role="note"
            >
                <strong>Documento em revisão.</strong> A identificação do
                responsável e os canais de contato ainda precisam ser concluídos
                antes da publicação definitiva deste documento.
            </div>
            <template v-if="isPrivacy">
                <nav class="policy-index" aria-label="Nesta política">
                    <h2>Nesta política</h2>
                    <ol>
                        <li v-for="[id, label] in sections" :key="id">
                            <a :href="`#${id}`">{{ label }}</a>
                        </li>
                    </ol>
                </nav>
                <article
                    class="document-body"
                    aria-label="Política de privacidade"
                >
                    <section
                        id="responsavel"
                        aria-labelledby="responsavel-title"
                    >
                        <h2 id="responsavel-title">1. Quem trata os dados</h2>
                        <p>
                            O chAIt é uma plataforma de atendimento e automação
                            de conversas para equipes. Esta política abrange os
                            dados de quem utiliza a plataforma e de contatos
                            atendidos pelas empresas que a utilizam.
                        </p>
                        <p v-if="legal.entity">
                            O responsável pela plataforma é
                            <strong>{{ legal.entity }}</strong>
                            <span v-if="legal.tax_id">
                                ({{ legal.tax_id }})</span
                            >.
                        </p>
                        <p>
                            No cadastro e na administração de contas, o
                            responsável pela plataforma define as finalidades do
                            tratamento. Nos atendimentos, a empresa que conversa
                            com você define como seus dados serão utilizados, e
                            o chAIt processa essas informações em nome dela.
                            Para dúvidas sobre um atendimento específico,
                            procure também essa empresa.
                        </p>
                    </section>
                    <section id="dados" aria-labelledby="dados-title">
                        <h2 id="dados-title">2. Dados que tratamos</h2>
                        <ul>
                            <li>
                                <strong>Conta e equipe:</strong> nome, e-mail,
                                senha protegida por hash, vínculo com a empresa,
                                funções e permissões de acesso.
                            </li>
                            <li>
                                <strong>Contatos e atendimento:</strong>
                                telefone, nome, e-mail, CPF e outros campos
                                preenchidos pela empresa ou pelo contato, além
                                de etiquetas, notas, responsáveis e situação dos
                                atendimentos.
                            </li>
                            <li>
                                <strong>Conversas e arquivos:</strong>
                                mensagens, imagens, áudios, vídeos, documentos e
                                metadados necessários para enviar, receber e
                                exibir o atendimento e executar automações.
                            </li>
                            <li>
                                <strong>Integrações:</strong> identificadores de
                                conexão, credenciais fornecidas pelos
                                administradores e configurações dos serviços
                                conectados.
                            </li>
                            <li>
                                <strong>Uso e segurança:</strong> dados de
                                sessão, endereço IP e registros técnicos de
                                acesso, operações e falhas, conforme a
                                infraestrutura utilizada.
                            </li>
                        </ul>
                        <p>
                            Esses dados podem ser fornecidos no cadastro, pela
                            equipe responsável pelo atendimento, pelas mensagens
                            que você envia ou pelas integrações habilitadas pela
                            empresa. Evite enviar dados sensíveis ou documentos
                            que não sejam necessários ao atendimento.
                        </p>
                    </section>
                    <section
                        id="finalidades"
                        aria-labelledby="finalidades-title"
                    >
                        <h2 id="finalidades-title">3. Como usamos os dados</h2>
                        <p>
                            Utilizamos dados para criar e autenticar contas,
                            organizar equipes e contatos, disponibilizar
                            conversas, enviar mensagens, executar fluxos e
                            sequências, prestar suporte e proteger o acesso à
                            plataforma.
                        </p>
                        <p>
                            Conforme a finalidade e o contexto, o tratamento
                            pode se apoiar na execução de contrato ou de
                            procedimentos solicitados pelo titular, no
                            cumprimento de obrigações legais, no exercício
                            regular de direitos ou em interesses legítimos,
                            respeitados os direitos do titular. Quando o
                            consentimento for a base aplicável, ele deve ser
                            solicitado de forma específica e pode ser revogado.
                            A empresa responsável pelo atendimento deve definir
                            a base legal das atividades que realiza.
                        </p>
                    </section>
                    <section
                        id="compartilhamento"
                        aria-labelledby="compartilhamento-title"
                    >
                        <h2 id="compartilhamento-title">
                            4. Integrações e compartilhamento
                        </h2>
                        <p>
                            Os dados do atendimento ficam acessíveis aos membros
                            da equipe de acordo com suas permissões. Para operar
                            o serviço, também são utilizados os seguintes tipos
                            de fornecedores e integrações:
                        </p>
                        <ul>
                            <li>
                                <strong>UAZAPI e WhatsApp:</strong> conexão do
                                canal, envio e recebimento de mensagens,
                                consulta de histórico e obtenção de mídias.
                            </li>
                            <li>
                                <strong>OpenAI, quando habilitada:</strong>
                                processamento de conteúdo necessário aos
                                recursos de inteligência artificial configurados
                                pela empresa.
                            </li>
                            <li>
                                <strong>Infraestrutura e e-mail:</strong>
                                hospedagem, armazenamento, processamento e
                                entrega de comunicações de conta e segurança.
                            </li>
                            <li>
                                <strong
                                    >Serviços conectados pela empresa:</strong
                                >
                                dados enviados ou recebidos por webhooks e
                                outras integrações, conforme a configuração dos
                                fluxos.
                            </li>
                        </ul>
                        <p>
                            Também poderá haver compartilhamento necessário ao
                            cumprimento de obrigação legal ou de determinação de
                            autoridade competente. Fornecedores podem processar
                            dados fora do Brasil; as condições, os locais de
                            processamento e os mecanismos aplicáveis dependem do
                            serviço contratado e devem ser considerados pela
                            empresa ao habilitar a integração.
                        </p>
                    </section>
                    <section
                        id="inteligencia-artificial"
                        aria-labelledby="ia-title"
                    >
                        <h2 id="ia-title">5. Inteligência artificial</h2>
                        <p>
                            Quando a empresa ativa os recursos de IA, mensagens,
                            arquivos e informações do contato podem ser enviados
                            à OpenAI para gerar respostas e executar ações
                            autorizadas no atendimento. O conteúdo enviado
                            depende do recurso e da configuração da empresa.
                        </p>
                        <p>
                            O contexto da conversa pode permanecer no provedor
                            durante a sessão. Ao encerrá-la, o sistema solicita
                            a limpeza desse contexto; a retenção pelo fornecedor
                            segue as condições do serviço utilizado. Respostas
                            automáticas podem conter erros. Para esclarecer ou
                            contestar um resultado, procure a empresa
                            responsável pelo atendimento.
                        </p>
                    </section>
                    <section id="cookies" aria-labelledby="cookies-title">
                        <h2 id="cookies-title">
                            6. Cookies e armazenamento local
                        </h2>
                        <p>
                            A plataforma utiliza cookies para manter a sessão,
                            proteger formulários e lembrar preferências de
                            navegação. Recursos da área de atendimento também
                            podem armazenar dados de conversas no navegador para
                            agilizar a exibição e preservar temporariamente o
                            trabalho em andamento.
                        </p>
                        <p>
                            Você pode gerenciar cookies e dados locais nas
                            configurações do navegador. Bloqueá-los ou
                            removê-los pode encerrar sua sessão e afetar
                            funcionalidades. Em dispositivos compartilhados,
                            encerre a sessão após o uso.
                        </p>
                    </section>
                    <section id="seguranca" aria-labelledby="seguranca-title">
                        <h2 id="seguranca-title">7. Segurança</h2>
                        <p>
                            A plataforma utiliza controles de acesso por equipe
                            e permissão, senhas protegidas por hash e
                            criptografia de credenciais de integrações
                            armazenadas no servidor. A proteção dos dados também
                            depende da configuração da infraestrutura e do uso
                            adequado das contas pelas equipes.
                        </p>
                        <p>
                            Não compartilhe senhas, tokens ou códigos de
                            verificação. Se identificar acesso indevido ou
                            suspeita de exposição de dados, utilize os
                            <a href="#contato">canais de contato</a> desta
                            política.
                        </p>
                    </section>
                    <section id="retencao" aria-labelledby="retencao-title">
                        <h2 id="retencao-title">8. Retenção e exclusão</h2>
                        <p>
                            O chAIt mantém dados de cadastro, contatos,
                            configurações e registros operacionais, incluindo
                            prévias de mensagens. O histórico das conversas é
                            consultado no provedor de mensagens, e recursos do
                            atendimento podem usar cópias temporárias no
                            navegador. Arquivos utilizados em automações também
                            podem ficar armazenados na plataforma.
                        </p>
                        <p>
                            A conservação e a exclusão dependem da finalidade,
                            das obrigações legais e da necessidade de exercício
                            de direitos. Dados em fornecedores e cópias de
                            segurança seguem também os procedimentos desses
                            serviços. Consulte o responsável para informações
                            sobre os prazos aplicáveis ao seu caso.
                        </p>
                        <div class="exclusion-note">
                            <h3>Como solicitar a exclusão da conta</h3>
                            <p>
                                Acesse a
                                <a href="/exclusao-de-conta"
                                    >página de solicitação de exclusão</a
                                >
                                e confirme seu e-mail. Após a confirmação, o
                                acesso é bloqueado e o pedido segue para análise
                                administrativa. Essa etapa não exclui
                                automaticamente os dados da plataforma nem dos
                                fornecedores conectados.
                            </p>
                            <p>
                                Se você é um contato atendido por uma empresa,
                                solicite a exclusão dos seus dados diretamente a
                                ela. O pedido de exclusão de uma conta de
                                usuário é diferente da exclusão dos dados de um
                                contato.
                            </p>
                        </div>
                    </section>
                    <section id="direitos" aria-labelledby="direitos-title">
                        <h2 id="direitos-title">9. Seus direitos</h2>
                        <p>
                            Nos termos da Lei Geral de Proteção de Dados
                            Pessoais (LGPD), e observadas as condições
                            aplicáveis, você pode solicitar gratuitamente:
                        </p>
                        <ul>
                            <li>
                                Confirmação do tratamento e acesso aos seus
                                dados.
                            </li>
                            <li>
                                Correção de dados incompletos, inexatos ou
                                desatualizados.
                            </li>
                            <li>
                                Anonimização, bloqueio ou eliminação de dados
                                desnecessários, excessivos ou tratados em
                                desconformidade com a lei.
                            </li>
                            <li>
                                Portabilidade, conforme a regulamentação
                                aplicável.
                            </li>
                            <li>
                                Informações sobre compartilhamento e, quando
                                aplicável, sobre a possibilidade de não
                                consentir e suas consequências.
                            </li>
                            <li>
                                Revogação do consentimento e eliminação dos
                                dados tratados com essa base, ressalvadas as
                                hipóteses legais de conservação.
                            </li>
                            <li>
                                Oposição ao tratamento nas hipóteses legais e
                                revisão de decisões tomadas unicamente por
                                tratamento automatizado que afetem seus
                                interesses.
                            </li>
                        </ul>
                        <p>
                            Para proteger seus dados, pode ser necessário
                            confirmar sua identidade antes de atender ao pedido.
                            Você também pode consultar as
                            <a
                                href="https://www.gov.br/anpd/pt-br/assuntos/titular-de-dados/direito-dos-titulares"
                                >orientações da ANPD sobre seus direitos</a
                            >
                            e recorrer à autoridade quando não conseguir
                            exercê-los perante o responsável.
                        </p>
                    </section>
                    <section id="contato" aria-labelledby="contato-title">
                        <h2 id="contato-title">10. Contato e atualizações</h2>
                        <p v-if="contactEmail">
                            Para dúvidas ou solicitações de privacidade, escreva
                            para
                            <a :href="`mailto:${contactEmail}`">{{
                                contactEmail
                            }}</a
                            >. Informe sua relação com a plataforma e descreva o
                            pedido, sem enviar senhas ou códigos de acesso.
                        </p>
                        <p v-else>
                            Para encaminhar dúvidas ou solicitações de
                            privacidade enquanto o canal específico é concluído,
                            acesse nossa
                            <a href="/suporte">página de suporte</a>. Não envie
                            senhas ou códigos de acesso.
                        </p>
                        <p>
                            Esta política pode ser atualizada para refletir
                            mudanças na plataforma e nas condições de
                            tratamento. A data no início da página identifica a
                            versão disponível para consulta.
                        </p>
                    </section>
                </article>
            </template>
            <article v-else class="document-body" aria-label="Termos de uso">
                <p v-if="legal.entity">
                    Responsável: {{ legal.entity }}
                    <span v-if="legal.tax_id">— {{ legal.tax_id }}</span>
                </p>
                <p>
                    O chAIt oferece ferramentas de atendimento e automação para
                    equipes. O usuário é responsável por suas credenciais e pelo
                    uso adequado dos dados tratados no serviço.
                </p>
                <p>
                    O uso de integrações de terceiros depende das condições
                    desses fornecedores. O acesso pode ser bloqueado após
                    solicitação de exclusão.
                </p>
                <p>
                    Consulte também a
                    <a href="/privacidade">política de privacidade</a>.
                </p>
            </article>
        </main>
        <footer class="public-footer">
            <span>chAIt · Privacidade e transparência</span>
            <nav aria-label="Informações legais">
                <a
                    href="/privacidade"
                    :aria-current="isPrivacy ? 'page' : undefined"
                    >Privacidade</a
                >
                <a
                    href="/termos"
                    :aria-current="!isPrivacy ? 'page' : undefined"
                    >Termos de uso</a
                >
                <a href="/suporte">Suporte</a>
            </nav>
        </footer>
    </div>
</template>

<style scoped>
.legal-page {
    min-height: 100dvh;
    background: var(--background);
    color: var(--foreground);
}
.public-header,
.public-footer {
    width: min(100%, 1088px);
    margin-inline: auto;
    padding: 24px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}
.public-header {
    border-bottom: 1px solid var(--border);
}
.brand-link {
    display: flex;
    align-items: center;
    min-width: 0;
    max-width: 160px;
    min-height: 44px;
}
.support-link {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    font-size: 14px;
    font-weight: 600;
    flex-shrink: 0;
}
.legal-content {
    width: min(100%, 824px);
    margin-inline: auto;
    padding: 64px 32px 80px;
}
.document-header {
    margin-bottom: 32px;
}
.eyebrow {
    margin: 0 0 16px;
    color: var(--muted-foreground);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}
h1 {
    margin: 0;
    font-size: clamp(32px, 5vw, 48px);
    font-weight: 600;
    letter-spacing: -0.04em;
    line-height: 1.15;
}
.introduction {
    margin: 20px 0 0;
    color: var(--muted-foreground);
    font-size: 18px;
    line-height: 1.7;
}
.document-date {
    margin: 20px 0 0;
    color: var(--muted-foreground);
    font-size: 13px;
}
.review-note {
    margin-bottom: 32px;
    padding: 16px 20px;
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--muted-foreground);
    font-size: 14px;
    line-height: 1.7;
}
.policy-index {
    margin-bottom: 48px;
    padding: 24px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
}
.policy-index h2 {
    margin: 0 0 16px;
    font-size: 16px;
    font-weight: 600;
}
.policy-index ol {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 32px;
    margin: 0;
    padding-left: 24px;
    list-style: decimal;
    font-size: 14px;
    line-height: 1.6;
}
.policy-index a {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    padding-block: 8px;
}
.document-body {
    color: var(--muted-foreground);
    font-size: 16px;
    line-height: 1.85;
    overflow-wrap: anywhere;
}
.document-body section {
    scroll-margin-top: 24px;
}
.document-body section + section {
    margin-top: 40px;
    padding-top: 32px;
    border-top: 1px solid var(--border);
}
.document-body h2 {
    margin: 0 0 16px;
    color: var(--foreground);
    font-size: 23px;
    font-weight: 600;
    letter-spacing: -0.02em;
    line-height: 1.4;
}
.document-body p {
    margin: 0 0 16px;
}
.document-body p:last-child {
    margin-bottom: 0;
}
.document-body ul {
    margin: 0 0 20px;
    padding-left: 24px;
    list-style: disc;
}
.document-body li + li {
    margin-top: 12px;
}
.document-body strong {
    color: var(--foreground);
    font-weight: 600;
}
a {
    color: var(--brand-strong);
    text-underline-offset: 4px;
}
.document-body a,
a:hover {
    text-decoration: underline;
}
a:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 4px;
    border-radius: 2px;
}
.exclusion-note {
    margin-top: 24px;
    padding: 24px;
    border-left: 3px solid var(--primary);
    border-radius: 0 8px 8px 0;
    background: var(--card);
}
.exclusion-note h3 {
    margin: 0 0 12px;
    color: var(--foreground);
    font-size: 17px;
    font-weight: 600;
}
.public-footer {
    border-top: 1px solid var(--border);
    color: var(--muted-foreground);
    font-size: 13px;
}
.public-footer nav {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 24px;
}
.public-footer a {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
}
.public-footer a[aria-current='page'] {
    font-weight: 600;
}
.skip-link {
    position: absolute;
    top: 8px;
    left: 16px;
    z-index: 10;
    padding: 12px;
    transform: translateY(-200%);
    background: var(--card);
}
.skip-link:focus {
    transform: translateY(0);
}
@media (max-width: 600px) {
    .public-header,
    .public-footer {
        padding: 20px;
        gap: 16px;
    }
    .brand-link {
        max-width: 128px;
    }
    .support-link {
        font-size: 13px;
    }
    .legal-content {
        padding: 40px 20px 56px;
    }
    .policy-index {
        padding: 20px;
    }
    .policy-index ol {
        grid-template-columns: 1fr;
    }
    .public-footer {
        align-items: flex-start;
        flex-direction: column;
    }
    .exclusion-note {
        padding: 20px;
    }
}
</style>
