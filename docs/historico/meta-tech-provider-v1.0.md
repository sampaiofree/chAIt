> **Versão histórica v1.0:** consulte o [roteiro atualizado v1.1](../integracoes/meta-tech-provider.md).

# CHAIT — Roteiro de credenciamento como Meta WhatsApp Tech Provider

> **Documento:** `docs/integracoes/meta-tech-provider.md`  
> **Versão:** 1.0 — 13/10/2026  
> **Estado:** Plano de execução / checklist. **Não equivale a credenciamento aprovado nem a integração implementada.**  
> **Objetivo:** permitir que clientes do CHAIT conectem sua própria conta de WhatsApp Business à **Cloud API oficial da Meta**, usando **Embedded Signup v4**, com **cobrança do uso da API diretamente pela Meta ao cliente** e assinatura do SaaS cobrada separadamente pelo CHAIT.

## 1. Decisão de produto e limites

- **Decidido em 13/10/2026:** buscar o credenciamento do CHAIT como **Independent Tech Provider**, com integração direta à Meta (não é necessário tornar-se Solution Partner nem oferecer linha de crédito para isso).
- O cliente deve ser titular de seu **Meta Business Portfolio**, de sua **WhatsApp Business Account (WABA)** e do número conectado. A autorização para o CHAIT atuar em seu nome deve ocorrer por Embedded Signup.
- **Cobrança pretendida:** a Meta cobra do cliente o tráfego tarifado da WhatsApp Business Platform; o CHAIT cobra sua própria assinatura. O cliente deverá cadastrar/validar o método de pagamento aplicável na Meta e aceitar os termos vigentes. O CHAIT não promete gratuidade da API.
- **Não confundir:** Tech Provider (credenciamento técnico), Tech Partner (nível posterior com critérios próprios) e Solution Partner (pode compartilhar linha de crédito). Credenciar-se como Tech Provider **não** significa obter selo ou status de Tech Partner.
- O plano aprovado anteriormente para primeira entrega usava **UAZAPI**. Este documento **abre uma trilha de integração oficial**; a substituição do conector inicial, a data da migração e a política de histórico **não são alteradas por suposição**.
- Padrão arquitetural do CHAIT: Laravel + Vue 3/Inertia, um número de WhatsApp por workspace na versão inicial, credenciais segregadas por workspace, autorização imposta pelo backend.

### Fontes e grau de certeza

A Meta apresenta oficialmente os tipos de parceiros, capacidades e restrição de linha de crédito em https://whatsappbusiness.com/partners/become-a-partner/. O roteiro de telas é fundamentado principalmente nos guias operacionais atualizados de Twilio e Infobip, indicados na seção 9. **Os menus, formulários, critérios de revisão e permissões podem mudar sem aviso**; a pendência mostrada no painel do aplicativo da Meta prevalece. Não foi possível autenticar na conta empresarial do CHAIT para inspecionar suas exigências específicas.

## 2. Mapa de execução

**Legenda:** `[ ]` não iniciado; `[~]` em andamento; `[x]` concluído e comprovado; `[!]` bloqueado. Não marcar como concluído sem guardar evidência.

| ID | Etapa | Evidência de conclusão | Status |
|---|---|---|---|
| TP-01 | Preparar empresa, domínio e documentos | Dados e URLs conferidos | [ ] |
| TP-02 | Criar/revisar portfólio Meta e segurança | Portfólio e administradores definidos; 2FA | [ ] |
| TP-03 | Concluir Business Verification | Meta mostra empresa verificada | [ ] |
| TP-04 | Criar aplicativo Meta para o CHAIT | App ID e vínculo ao portfólio | [ ] |
| TP-05 | Adicionar WhatsApp e iniciar Independent Tech Provider | Termos aceitos e onboarding iniciado | [ ] |
| TP-06 | Configurar dados, páginas legais e ambiente demonstrável | Checklist de configurações concluído | [ ] |
| TP-07 | Solicitar Advanced Access e fazer App Review | Permissões aprovadas | [ ] |
| TP-08 | Concluir Access Verification | Meta confirma verificação de acesso | [ ] |
| TP-09 | Criar Embedded Signup v4 | Configuration ID e OAuth configurados | [ ] |
| TP-10 | Implementar conexão OAuth e registro no Laravel | Teste de conexão ponta a ponta | [ ] |
| TP-11 | Implementar mensagens, webhooks e operações | Teste de envio/recebimento e idempotência | [ ] |
| TP-12 | Tratar cobrança e política de uso do cliente | Cliente configurou pagamento e regras | [ ] |
| TP-13 | Testar isolamento, segurança, número e desconexão | Matriz de testes aprovada | [ ] |
| TP-14 | Publicar aplicativo e piloto com cliente autorizado | App Live + cliente real funcionando | [ ] |
| TP-15 | Operar, dar suporte e manter conformidade | Procedimentos de monitoramento ativos | [ ] |

> **A ordem TP-07 / TP-08 poderá variar:** em alguns painéis, a Meta exige Access Verification antes ou durante App Review; em outros, a tarefa aparece após aprovação. Executar na ordem liberada no painel, sem considerar uma verificação substituta da outra.

## 3. Etapas de credenciamento (administrativas)

### TP-01 — Preparar a empresa e a presença pública

**Responsável:** titular/administrador da empresa.

**Ações:**
1. Identificar **qual pessoa jurídica** será a titular do portfólio e do aplicativo CHAIT; conferir razão social, CNPJ, endereço, telefone e contatos comerciais.
2. Garantir site oficial do produto com **HTTPS**, descrição legítima do serviço, contato de suporte e domínio corporativo consistente com a empresa.
3. Publicar **Política de Privacidade**, **Termos de Uso** e mecanismo/documentação de **solicitação de exclusão de dados** (página ou instruções e, quando exigido, callback).
4. Separar documentos empresariais oficiais e comprovação de endereço/telefone que coincidam com os dados registrados.
5. Documentar os papéis de controlador/operador de dados e subprocessadores; preparar respostas reais sobre armazenamento, compartilhamento, retenção, exclusão e segurança. Considerar LGPD no tratamento de mensagens, contatos e dados opcionais (como CPF).

**Pronto quando:** informações empresariais e páginas públicas estiverem consistentes, acessíveis sem login e com responsáveis definidos. **Não** inventar políticas que o sistema ainda não cumpre.

**Risco:** divergência de dados jurídicos ou domínio dificulta/reprova a verificação.

### TP-02 — Portfólio empresarial, desenvolvedor e segurança

**Responsável:** administrador da empresa.

**Ações:**
1. Acessar https://business.facebook.com/ e **reutilizar** um portfólio empresarial legítimo existente, ou criar um para a empresa titular do CHAIT.
2. Confirmar que o administrador do futuro aplicativo tem acesso administrativo ao portfólio correto.
3. Habilitar a exigência de **autenticação em dois fatores (2FA)** para pessoas com acesso empresarial e revisar os administradores.
4. Criar/habilitar acesso de desenvolvedor em https://developers.facebook.com/.
5. Se a opção **Business Verification** não estiver disponível, seguir a elegibilidade indicada pelo painel; o uso inicial da WhatsApp Business Platform pela própria empresa pode desbloquear a verificação em alguns cenários.

**Pronto quando:** portfólio identificado, responsável e 2FA conferidos.

**Risco:** usar um portfólio antigo de terceiro, sem controle administrativo, pode inviabilizar a operação.

### TP-03 — Business Verification (verificação da empresa)

**Responsável:** administrador empresarial.

**Ações:**
1. No Meta Business Suite / configurações do negócio, procurar **Security Center / Business Verification** (rótulos podem variar).
2. Solicitar a verificação do portfólio ao qual o aplicativo será vinculado.
3. Preencher dados exatamente conforme documentos legais; concluir o desafio de contato ou domínio, quando solicitado.
4. Enviar documentos exigidos e acompanhar pendências, recusas e pedidos de correção.
5. Guardar confirmação de empresa **Verified** (não confundir com Meta Verified pago ou selo em perfil).

**Pronto quando:** estado de verificação empresarial aprovado no portfólio certo.

**Risco:** não estabelecer prazo fixo; o tempo varia e correções podem exigir nova análise.

### TP-04 — Criar o aplicativo Meta do CHAIT

**Responsável:** desenvolvedor com autorização administrativa.

**Ações:**
1. Em https://developers.facebook.com/apps/, escolher **Create App**.
2. Criar um aplicativo dedicado ao CHAIT, selecionando caso de uso e tipo empresarial compatíveis com WhatsApp conforme as opções **atuais** do assistente (em alguns guias aparecem **Other → Business**).
3. Informar nome comercial sem uso indevido das marcas/logotipos Meta ou WhatsApp, e-mail de suporte e **associar ao portfólio empresarial correto**.
4. Guardar **App ID**, portfólio e responsáveis no inventário técnico. O **App Secret** deve ser guardado em gerenciador de segredos, nunca no `.md`, Git ou frontend.

**Pronto quando:** o aplicativo aparece no portfólio correto com App ID identificável.

### TP-05 — Ativar WhatsApp e aderir ao programa

**Responsável:** administrador/desenvolvedor.

**Ações:**
1. No App Dashboard, adicionar o produto/caso de uso **WhatsApp** e acessar **WhatsApp → Quickstart**.
2. Procurar **Scale your business → Become a Tech Provider → Start onboarding** (ou equivalente).
3. Selecionar **Independent Tech Provider** — e não *Working with a Solution Partner* — para o objetivo de integrar diretamente à Cloud API.
4. Ler e aceitar os termos que a Meta efetivamente apresentar, incluindo obrigações do prestador de serviço e restrições de uso.
5. Registrar quais tarefas o painel desbloqueia e eventuais restrições de conta/região.

**Pronto quando:** o painel mostra o processo Independent Tech Provider iniciado.

**Risco:** selecionar trilha de Solution Partner introduz dependências e chamadas específicas de terceiros que não pertencem ao objetivo do CHAIT.

### TP-06 — Completar dados do app e protótipo de demonstração

**Responsável:** produto e desenvolvimento.

**Ações:**
1. Em **App Settings → Basic**, configurar ícone, categoria, domínio, e-mail e URLs legais solicitadas.
2. Configurar plataforma **Website**, endereço HTTPS funcional e, quando solicitados, política de privacidade, instruções de exclusão de dados e termos.
3. Disponibilizar um ambiente mínimo e funcional com **envio de mensagem, recebimento por webhook e gestão de template**, capaz de demonstrar as permissões solicitadas.
4. Preparar um usuário/perfil de teste e instruções reproduzíveis para o revisor, sem dados reais de clientes.
5. Rever **Platform Terms**, **Developer Policies** e **WhatsApp Business Messaging Policy** vigentes.

**Pronto quando:** fluxo demonstrável e todas as URLs estão acessíveis; não é necessário concluir todo o CRM ou editor de automações para começar a revisão.

**Risco:** tentar a revisão de uma funcionalidade inexistente leva à recusa. Não apresentar apenas telas decorativas como comportamento real.

### TP-07 — Solicitar permissões e passar pelo App Review

**Responsável:** desenvolvimento + responsável pelos dados.

**Ações:**
1. Em **App Review → Permissions and Features**, solicitar **Advanced Access** para:
   - `whatsapp_business_messaging`: envio de mensagens em nome de WABAs autorizadas.
   - `whatsapp_business_management`: administração de ativos de WhatsApp, como modelos, em nome dos clientes.
2. Verificar as permissões adicionais que **a configuração efetiva de Embedded Signup v4** requer. Algumas referências da Meta incluem `business_management` em determinados fluxos; **não requerer permissões extras indiscriminadamente**, mas cumprir o checklist gerado pela própria Meta.
3. Para cada permissão, explicar o **uso concreto** no CHAIT, por que é necessário, que dados serão acessados e o benefício para a empresa cliente.
4. Gravar vídeo demonstrando o uso real: por exemplo, envio do CHAIT e recebimento em WhatsApp; criação/gestão de template para a permissão correspondente. Seguir formato, idioma/legendas e orientações da revisão exibidos no painel.
5. Responder corretamente às **Data Handling Questions**, inclusive retenção, exclusão, acesso, processamento e terceiros envolvidos; indicar URL e credenciais de teste se exigidas.
6. Revisar e enviar a solicitação; tratar feedback de rejeição, corrigir problema e reapresentar as evidências.

**Pronto quando:** Advanced Access necessário está **Approved** e não apenas **Requested / In Review**.

**Risco:** aprovação do aplicativo/permissões **não é** sinônimo automático de aprovação como Tech Provider; ainda pode haver Access Verification.

### TP-08 — Access Verification (legitimidade do acesso a dados de empresas)

**Responsável:** administrador empresarial.

**Ações:**
1. No App Dashboard, localizar **Access Verification** (em guias recentes, **App Settings → Basic → Verifications**; localização exata depende do painel).
2. Explicar que o CHAIT presta serviço SaaS para empresas terceiras, cujo administrador concede acesso aos ativos WhatsApp pelo Embedded Signup.
3. Informar modelo de negócios, o que a plataforma faz, como obtém consentimento/permissão, quais dados empresariais manipula e como os protege.
4. Anexar as provas pedidas, enviar e acompanhar o resultado.
5. Se o painel trouxer essa etapa **antes ou dentro** do App Review, fazê-la nessa posição.

**Pronto quando:** a verificação de acesso exigida pelo painel está **Approved**.

**Risco:** a verificação da pessoa jurídica não substitui a demonstração do uso autorizado de recursos de outros negócios.

## 4. Etapas técnicas da integração direta (sem BSP obrigatório)

### TP-09 — Criar Embedded Signup **v4** e configurar OAuth

**Responsável:** desenvolvedor Laravel/Vue.

**Ações:**
1. No App Dashboard, configurar **Facebook Login for Business** e criar configuração com variação **WhatsApp Embedded Signup**.
2. Selecionar **WhatsApp Cloud API** e somente os produtos necessários ao CHAIT; não habilitar Marketing Messages API ou outros produtos sem necessidade definida.
3. Configurar token/tipo de acesso, ativos **WhatsApp accounts**, permissões exigidas e obter **Configuration ID**.
4. Configurar **Valid OAuth Redirect URIs**, domínios permitidos para JavaScript SDK, HTTPS e restrições de OAuth conforme o painel.
5. Implementar **Embedded Signup v4 desde o início**. Versões v2/v3 têm descontinuação anunciada para **15/10/2026** (confira avisos da Meta na data da implantação).

**Pronto quando:** `config_id` está vinculado ao app correto e o fluxo abre em domínio HTTPS permitido.

**Risco:** não copiar guias antigos de v2/v3 nem parâmetros específicos de integrações Twilio/Infobip; o CHAIT utilizará **Graph/Cloud API diretamente**.

### TP-10 — Finalizar Embedded Signup com segurança no backend

**Responsável:** desenvolvimento.

**Ações:**
1. Na interface do workspace, oferecer **Conectar WhatsApp oficial**, abrir o login da Meta e deixar o cliente escolher/criar portfólio/WABA e comprovar seu número quando exigido.
2. Processar eventos de sucesso, cancelamento e erro; conferir rigorosamente a origem do evento e tratar o resultado como **dados não confiáveis** até verificação no backend.
3. Capturar `waba_id`, `phone_number_id` e o código de autorização **quando fornecidos pelo fluxo utilizado**.
4. Trocar o `code` por credencial de negócio no **backend**, sem expor App Secret; utilizar fluxo de token e validade compatíveis com a configuração e versão atuais da Meta.
5. Associar os ativos ao `workspace_id` autenticado com validação de posse, idempotência e unicidade; **um número por workspace** no primeiro lançamento.
6. Inscrever a WABA nos webhooks por `subscribed_apps`, e executar **registro do número**/PIN/etapas de ativação quando exigidas pela situação do número.
7. Salvar segredos criptografados; permitir consulta de status, reconexão e revogação. **Não** guardar tokens em logs, no navegador ou em Markdown.

**Pronto quando:** uma WABA de teste autorizada é vinculada ao workspace certo e a conexão pode ser ativada e revogada.

**Risco:** `waba_id` obtido no navegador **não comprova sozinho** autorização operacional; concluir token, assinatura de webhook e registro de telefone conforme fluxo.

### TP-11 — Implementar Cloud API, webhooks e templates

**Responsável:** desenvolvimento.

**Ações:**
1. Construir um adaptador `MetaCloudWhatsAppProvider` separado do `UazapiProvider`; não codificar regras da Meta espalhadas no domínio de atendimento.
2. Implementar envio de mensagens/mídias conforme formatos, autenticação, políticas de janela de atendimento e modelos da Cloud API.
3. Configurar webhook público em **HTTPS**, verificação do endpoint e validação criptográfica da assinatura do payload (conforme documentação atual).
4. Inscrever os eventos necessários: mensagens recebidas, status de entrega/leitura e atualizações relevantes de conta/número, de acordo com os recursos disponíveis.
5. Aplicar **idempotência**, proteção contra eventos atrasados, filas Redis/Horizon, tentativas controladas e prevenção de envio duplicado.
6. Permitir gerenciamento de **message templates** (criação, consulta, aprovação/rejeição e uso quando necessário).
7. Não permitir que mensagens enviadas pelo próprio bot disparem gatilhos de entrada; suspender respostas automáticas durante atendimento humano conforme as regras do CHAIT.

**Pronto quando:** envio, recebimento, status e templates funcionam com uma conta de teste, sem vazamento entre workspaces.

### TP-12 — Cobrança da Meta e experiência do cliente

**Responsável:** produto/comercial + integração.

**Ações:**
1. Explicar no onboarding que existem **duas cobranças independentes**: assinatura CHAIT e consumo tarifado da Meta.
2. Orientar o cliente a configurar e confirmar **método de pagamento na Meta/WhatsApp Manager**, nos locais e condições habilitados para sua WABA e país.
3. Testar situações de método ausente, cobrança recusada, saldo/limite insuficiente e restrição operacional; apresentar erros úteis.
4. Não implementar **credit sharing**, faturamento consolidado ou revenda de tarifas da Meta: são características de outro modelo comercial e não fazem parte do plano Independent Tech Provider.
5. Exibir documentação/referência para categorias de mensagens, templates, consentimento, limites e precificação atual; **não fixar valores em código** nem prometer que todo atendimento é gratuito.

**Pronto quando:** o cliente entende o custo, cadastrou pagamento aplicável e o envio tarifado foi validado em piloto.

**Risco:** aprovação do aplicativo não garante que a WABA de um cliente está apta a enviar mensagens pagas; elegibilidade, cobrança e qualidade precisam ser verificadas individualmente.

### TP-13 — Testes de implantação e segurança

**Responsável:** desenvolvimento + QA.

**Critérios mínimos:**
- [ ] Cliente A conecta WABA A no workspace A; cliente B não vê token, mensagens ou ativos de A.
- [ ] Não é possível associar `waba_id` ou `phone_number_id` de outro workspace manipulando requisições.
- [ ] Webhook com assinatura inválida é rejeitado; webhook duplicado não cria ação/automação em duplicidade.
- [ ] Mensagem recebida, resposta humana, template, mídia e status funcionam com conta de teste.
- [ ] Token revogado/expirado e falha de cobrança mostram estado claro sem tentativas infinitas de envio.
- [ ] Desconectar/reconectar não transfere titularidade da WABA para o CHAIT nem deixa acesso indevido.
- [ ] Número novo, número usado anteriormente e eventual **coexistência com WhatsApp Business App** são tratados como cenários diferentes; validar elegibilidade e consequências antes de prometer uso simultâneo.
- [ ] Verificar política de opt-in, janela de 24 horas e templates conforme regras Meta vigentes.
- [ ] Verificar o comportamento de volumes, limites, qualidade da conta e bloqueios por política.
- [ ] Executar teste em **segundo portfólio Meta autorizado**, pois o portfólio titular do aplicativo pode não estar disponível como cliente no próprio Embedded Signup.

**Pronto quando:** os testes acima possuem evidência e os bloqueadores estão resolvidos.

### TP-14 — Publicação (Live) e piloto

**Responsável:** produto e desenvolvedor.

**Ações:**
1. Confirmar revisão e verificações completas, URLs de produção e configurações de domínio/OAuth.
2. Alterar o **App Mode** para **Live** quando o painel e as permissões autorizarem.
3. Conectar pelo Embedded Signup um negócio piloto que seja independente do portfólio titular do app, com autorização expressa do seu administrador.
4. Validar ligação entre WABA, número, cobrança do cliente, templates, webhook, atendimento no CHAIT e revogação.
5. Conferir limites de onboarding aplicados **ao seu portfólio** em **WhatsApp Manager → Partner overview**: documentação de parceiros menciona 10 novos clientes em 7 dias antes das verificações completas e até 200 após elas; **não tratar esses números como direito contratual ou limite permanente**.
6. Liberar a integração progressivamente e documentar incidentes do piloto.

**Pronto quando:** um cliente real autorizado consegue conectar, conversar, administrar pagamentos e desconectar com sucesso.

### TP-15 — Suporte e conformidade contínuos

**Responsável:** operação CHAIT.

**Ações:**
1. Monitorar webhooks, falhas de envio, autorização/revogação, disponibilidade da Graph API, cotas, qualidade do número e alertas de política.
2. Acompanhar vencimento/renovação ou revogação de tokens, mudanças de escopos e versão da Graph API.
3. Estabelecer procedimento de exclusão de dados e desligamento de cliente: revogação de tokens/acessos, remoção do vínculo do workspace e retenção conforme política divulgada.
4. Manter páginas legais, informações empresariais e evidências de uso coerentes com o comportamento real do produto.
5. Reavaliar a aplicação caso a Meta solicite nova revisão ou atualize o programa.

**Pronto quando:** existe rotina de acompanhamento e resposta a incidentes.

## 5. Bloqueador arquitetural do CHAIT: histórico das conversas

**Decisão anterior aprovada:** com UAZAPI, o CHAIT **não armazena o conteúdo/histórico das mensagens no Laravel**; busca as mensagens no provedor, aceitando retenção limitada.

**Diferença decisiva na Cloud API oficial:** os webhooks são o mecanismo principal de recebimento; **não planejar a tela de chat supondo um endpoint que forneça todo o histórico de conversas passadas**. As APIs de mensagens, status e insights **não equivalem a um repositório pesquisável de histórico completo** para reconstruir a caixa de entrada.

**Consequência:** antes de colocar a Cloud API como canal operacional principal, decidir e especificar **como manter a linha do tempo do chat** (por exemplo, armazenamento próprio com criptografia, retenção, exclusão, consentimentos e segurança apropriados). Esta decisão **ainda não está aprovada** e afeta tanto UI quanto LGPD, infraestrutura, suporte e custos.

**Ação preventiva:** é possível avançar no credenciamento e no protótipo técnico sem assumir, neste momento, uma política nova de retenção de mensagens em produção. Não lançar um chat prometendo histórico que a integração não consegue fornecer.

## 6. Organização sugerida no repositório e implementações futuras

```text
docs/
└── integracoes/
    ├── uazapi.md                   # integração já documentada
    └── meta-tech-provider.md       # ESTE DOCUMENTO
```

Quando o conector oficial entrar em desenvolvimento, preservar o contrato de `channel_connections` e o isolamento dos workspaces. **Nenhuma migration, mudança de integração inicial ou política de retenção é autorizada apenas pela criação deste documento.**

## 7. Evidências e acompanhamento

Recomenda-se manter registro para cada TP-XX com: **responsável, data, status, URL/tela consultada, evidência da aprovação ou erro, próximo impedimento**. Não registrar tokens, senhas, dados sensíveis de clientes nem documentos empresariais confidenciais neste arquivo. Evidências sensíveis pertencem a armazenamento privado.

Exemplo de atualização:

```md
### TP-03 — Business Verification
- Status: [~] Em análise
- Data do envio: AAAA-MM-DD
- Evidência: [referência interna sem documento sensível]
- Próxima ação: aguardar decisão/pedido da Meta
```

## 8. Primeiro passo a executar agora

**TP-01 / TP-02 (verificação inicial, sem criar recursos duplicados):** acessar https://business.facebook.com/settings, verificar se existe **um portfólio empresarial da pessoa jurídica que titularizará o CHAIT** e se há acesso de **administrador**. Só depois conferir documentos/verificação e criar o aplicativo. **Não criar outro portfólio sem checar o existente.**

## 9. Referências para conferir em cada fase

### Fontes oficiais da Meta

1. Ecossistema e distinção entre Tech Provider, Tech Partner e Solution Partner: https://whatsappbusiness.com/partners/become-a-partner/
2. Página oficial de ingresso no Tech Provider (a URL/rota pode redirecionar): https://developers.facebook.com/docs/whatsapp/solution-providers/get-started-for-tech-providers
3. Guia Meta de onboarding de clientes Tech Provider (nova estrutura documental): https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-customers-as-a-tech-provider
4. Visão geral Embedded Signup: https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/overview
5. Meta, coleção oficial Cloud API no Postman: https://www.postman.com/meta/whatsapp-business-platform/collection/wlk6lh4/whatsapp-cloud-api
6. Meta, coleção oficial Embedded Signup no Postman: https://www.postman.com/meta/whatsapp-business-platform/collection/du6gzjv/embedded-signup
7. Termos da Meta para WhatsApp Business (inclusive faturamento): https://www.whatsapp.com/legal/meta-terms-whatsapp-business
8. Desenvolvedores / apps: https://developers.facebook.com/apps/
9. Portfólio / configurações: https://business.facebook.com/settings

### Guias operacionais complementares (terceiros; adaptar ao uso direto da Cloud API)

10. Twilio, guia do programa Tech Provider: https://www.twilio.com/docs/whatsapp/isv/tech-provider-program/integration-guide
11. Twilio, FAQ do programa e limites: https://www.twilio.com/docs/whatsapp/isv/tech-provider-program/faq
12. Infobip, setup, permissões e verificações: https://www.infobip.com/docs/whatsapp/tech-provider-program/setup-and-integration
13. Twilio, aviso de migração do Embedded Signup v4 em 15/10/2026: https://help.twilio.com/articles/54890520784027
14. Meta, apresentação do Unified Onboarding/Embedded Signup v4 (2026): https://developers.meta.com/resources/videos/unified-onboarding-whatsapp/

> **Cuidado ao usar os guias de Twilio/Infobip:** partes referentes a **Partner Solution, solução vinculada, Senders API proprietária, subcontas de fornecedor ou compartilhamento de crédito** são exclusivas desses arranjos e **não devem** ser copiadas para a implementação direta do CHAIT.

---

**Controle de decisões**
- **Aprovado:** buscar Tech Provider independente; não atuar como intermediário de crédito/pagamento de mensagens.
- **Em aberto:** data de substituição da UAZAPI, armazenamento de mensagens da Cloud API, suporte a coexistência e migrações, experiência de cobrança/erros e escopo da implementação de templates.
- **Não iniciado/sem prova:** verificações do negócio, App Review, Access Verification, Embedded Signup funcional e lançamento em produção.
