# Registro de decisões do briefing

> **Atualizado em:** 2026-10-16  
> **Regra de trabalho:** discutir uma decisão por vez e registrar apenas o que for explicitamente confirmado.

## Decisões confirmadas nesta rodada

| ID   | Decisão                                                                                                                                                                                                                                                                                                         | Data                              | Documento responsável                                                                            |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- | ------------------------------------------------------------------------------------------------ |
| B-01 | UAZAPI como provedor inicial, com [documentação oficial](https://docs.uazapi.com/).                                                                                                                                                                                                                             | 2026-10-12                        | [Integração](../integracoes/uazapi.md)                                                           |
| B-02 | Um número de WhatsApp por workspace.                                                                                                                                                                                                                                                                            | 2026-10-12                        | [Multiatendimento](../modulos/chat-multiatendimento.md)                                          |
| B-03 | Frontend Vue 3; Vue Flow para o editor de fluxos. Inertia.js permanece na arquitetura descrita.                                                                                                                                                                                                                 | 2026-10-12                        | [Interface](../interfaces/chat.md) e [editor](../modulos/editor-de-fluxos.md)                    |
| B-04 | Começar pelo chat e multiatendimento; fluxos em etapa posterior.                                                                                                                                                                                                                                                | 2026-10-12                        | [Ordem de entrega](../README.md)                                                                 |
| B-05 | Ao finalizar, liberar o responsável ativo e preservar o último atendente e histórico operacional.                                                                                                                                                                                                               | 2026-10-12                        | [Multiatendimento](../modulos/chat-multiatendimento.md)                                          |
| B-06 | Histórico completo na UAZAPI; revisão de 16/10 permite prévia de até 200 caracteres e conteúdo temporário criptografado por até 24 horas no modo de tempo real.                                                                                                                                                 | 2026-10-12; revista em 2026-10-16 | [Multiatendimento](../modulos/chat-multiatendimento.md) e [integração](../integracoes/uazapi.md) |
| B-07 | Atendimento humano só abre por gatilho que o determine; sem ele, nova mensagem segue processamento normal de palavras-chave/fluxos.                                                                                                                                                                             | 2026-10-12                        | [Multiatendimento](../modulos/chat-multiatendimento.md)                                          |
| B-08 | Responsável atribuído ao lead ou administrador pode responder, finalizar e adicionar notas.                                                                                                                                                                                                                     | 2026-10-12                        | [Multiatendimento](../modulos/chat-multiatendimento.md)                                          |
| B-09 | Dois seletores: Todos / Meus chats / Não atribuídos e Todos / Abertos / Concluídos.                                                                                                                                                                                                                             | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |
| B-10 | Contato inicial com nome, email, CPF e notas. Substituída por B-23.                                                                                                                                                                                                                                             | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |
| B-11 | Anexos documento, imagem e vídeo; campo de busca na coluna de conversas.                                                                                                                                                                                                                                        | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |
| B-12 | Continuar com a mesma aplicação web, adaptada para desktop e celular; sem criar frontend mobile independente.                                                                                                                                                                                                   | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |
| B-13 | Antes dos gatilhos automáticos, abrir atendimento humano pelo botão manual **Abrir atendimento** no chat.                                                                                                                                                                                                       | 2026-10-12                        | [Multiatendimento](../modulos/chat-multiatendimento.md)                                          |
| B-14 | Administrador pode marcar no vínculo do usuário se ele pode visualizar conversas não atribuídas.                                                                                                                                                                                                                | 2026-10-12                        | [Multiatendimento](../modulos/chat-multiatendimento.md)                                          |
| B-15 | Resposta do administrador em conversa sem atendimento/responsável abre o atendimento e o atribui ao administrador.                                                                                                                                                                                              | 2026-10-12                        | [Multiatendimento](../modulos/chat-multiatendimento.md)                                          |
| B-16 | Decisão original: Abertos = somente Em atendimento; Concluídos = Finalizado. Diferença observada na implementação atual registrada na revisão de 16/10 abaixo.                                                                                                                                                  | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |
| B-17 | Notas gerais do contato e notas internas da conversa são campos separados. Substituída por B-23 na interface.                                                                                                                                                                                                   | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |
| B-18 | A busca inicial consulta nome e telefone do contato.                                                                                                                                                                                                                                                            | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |
| B-19 | Configuração e pareamento do WhatsApp ficam em Configurações, para administradores.                                                                                                                                                                                                                             | 2026-10-12                        | [Interface](../interfaces/chat.md) e [integração](../integracoes/uazapi.md)                      |
| B-20 | O perfil do contato permanece em coluna fixa de 290 px no desktop quando a conversa muda.                                                                                                                                                                                                                       | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |
| B-21 | Imagens enviadas aparecem imediatamente e são conciliadas com o histórico por identificador.                                                                                                                                                                                                                    | 2026-10-12                        | [Interface](../interfaces/chat.md) e [integração](../integracoes/uazapi.md)                      |
| B-22 | Mostrar foto do lead na lista, cabeçalho e perfil, com iniciais quando indisponível.                                                                                                                                                                                                                            | 2026-10-12                        | [Interface](../interfaces/chat.md) e [integração](../integracoes/uazapi.md)                      |
| B-23 | Gravar voz pelo microfone: parar e ouvir antes de enviar/descartar; limite de dois minutos e 10 MB; mesma aplicação desktop/mobile, sem anexar áudio existente.                                                                                                                                                 | 2026-10-12                        | [Interface](../interfaces/chat.md) e [integração](../integracoes/uazapi.md)                      |
| B-23 | Exibir apenas Notas internas no perfil, com histórico, autor e data. Ocultar Notas do contato sem migração; manter o campo no banco por enquanto.                                                                                                                                                               | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |
| B-24 | Mostrar bolinha verde clara sob o horário para mensagens não lidas; leitura compartilhada no workspace ao visualizar as mensagens mais recentes. Manter cabeçalho e compositor fixos, com rolagem no histórico.                                                                                                 | 2026-10-12                        | [Interface](../interfaces/chat.md) e [integração](../integracoes/uazapi.md)                      |
| B-25 | Administradores podem criar usuários diretamente em Configurações → Equipe, definir senha inicial e permissões por checks; emails existentes vinculam a conta sem alterar seus dados. Manter admin interno, sem seletor de papel. Troca de workspace junto ao avatar no desktop e no cabeçalho da lista mobile. | 2026-10-12                        | [Interface](../interfaces/chat.md)                                                               |

As decisões anteriores de isolamento, visibilidade, atribuição manual, transferência imediata e notas internas permanecem nos documentos dos módulos. As seções 4 e 5.2 de [Chat e multiatendimento](../modulos/chat-multiatendimento.md) distinguem o modelo de permissões/transferência do briefing do código atual.

## Histórico de mensagens — decisão original e revisão vigente

**Estado:** confirmado explicitamente em 12/10/2026, após apresentação da limitação do provedor.

**Regra original de 12/10:** não persistir conteúdo/histórico de mensagens no banco Laravel. **Regra vigente após 16/10:** histórico completo continua no provedor; o modo de tempo real permite prévias locais limitadas e processamento temporário criptografado conforme a revisão ao final deste documento e o [contrato operacional](../operacao/chat-realtime.md).

**Informação nova:** o [guia oficial de histórico](https://docs.uazapi.com/docs/collections/message-history-retention.md) informa retenção local de até sete dias, que pode ser menor sob alto volume. Recuperar mensagens anteriores depende do aparelho principal e não tem garantia de retorno.

**Escolha confirmada:** aceitar o histórico limitado da UAZAPI. A proibição original foi revista apenas para prévias e conteúdo temporário necessário ao processamento, não para histórico completo local. Notas internas, dados de contatos e histórico operacional continuam seguindo suas regras próprias.

**Consequência aceita:** o produto não garante acesso permanente ou completo às mensagens antigas. A interface atual informa retenção e histórico indisponível; esses avisos não prometem histórico completo ou permanente.

## Nova mensagem após finalização — decisão confirmada

**Regra aprovada:** a mensagem sozinha não abre atendimento humanizado. A abertura depende de um gatilho definido para isso. Caso contrário, a conversa segue o comportamento normal, avaliando palavras-chave, fluxos e demais regras aplicáveis ao seu estado.

Isso substitui a recomendação anterior de reabrir automaticamente como Aberto. A regra não aprova preferência pelo último atendente, remoção de pausa manual nem retomada de execução interrompida.

**Mecanismos atuais:** ação manual **Abrir atendimento**, operação de abertura nos fluxos e encaminhamento do bloco IA; mensagem recebida sozinha não abre atendimento humano.

## Web e mobile — direção esclarecida

**Decisão confirmada:** continuar utilizando a mesma aplicação web em Vue 3 + Inertia, com layout/navegação adaptados para computador e celular. Mesmo projeto frontend, backend Laravel e dados. A proposta anterior de frontend mobile independente não corresponde à intenção do usuário e foi substituída.

**Proposta opcional, ainda não aprovada:** tornar essa mesma aplicação instalável como PWA. Ela continuará acessível normalmente pelo navegador. Instalação, notificações e eventual distribuição em lojas precisam de definição posterior; não presumir uso de Capacitor nem outro frontend.

Uma PWA utiliza tecnologias web e pode oferecer instalação no dispositivo a partir da mesma base de código. [Referência MDN](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps).

**Primeira entrega:** chat web com adaptação mobile, respeitando a navegação já aprovada. A decisão não promete funcionamento offline nem persistência local de mensagens.

## Decisões complementares e estado das definições

### Bloco IA — decisões de 13/10/2026

O bloco IA usa chave OpenAI do workspace, configurada por administrador. O modelo padrão é GPT-6.1 Sol, com Luna e Astra como opções. A IA gera uma abertura a partir do prompt sem usar a mensagem que disparou o fluxo; entende texto, imagem, áudio transcrito e PDF/DOCX/TXT recebidos, mas responde em texto. Para vídeo, pede texto ou outra mídia e continua a sessão. A inatividade começa em uma hora e pode ser ajustada de cinco minutos a sete dias. O limite de mensagens é opcional e desligado por padrão; se ativado, o administrador escolhe entre Continuar e atendimento humano ao atingi-lo. Falha da OpenAI encaminha ao atendimento humano. O contrato completo está em [Bloco IA](../modulos/bloco-ia.md).

As homologações reais parciais de 14/10 estão registradas na evolução Assistente IA, incluindo envios e limpeza remota com entradas sintéticas e CRM isolado. Elas não certificam toda a operação de entrada/saída, dispositivos ou limites máximos.

1. Perfil implementado: nome/e-mail opcionais até 255 caracteres; e-mail válido; CPF opcional até 14 caracteres, sem dígitos verificadores nesse endpoint. O contrato atual está em Chat e multiatendimento §6.4.
2. Edição/exclusão de notas e demais detalhes de ações administrativas.
3. Pausas implementadas: abertura humana já bloqueia automações; finalizar libera só a pausa humana; pausas manuais são independentes e execuções interrompidas não retomam.
4. Detalhes operacionais da integração UAZAPI e limites de anexos no servidor contratado.
5. Captura/sobrescrita, interrupção de esperas, limites, falhas, permissões, exclusão e saídas sem conexão estão descritos no Editor e nos contratos dos blocos já implementados. Os itens de homologação não equivalem a pendência de implementação.

### Assistente IA — decisões de 14/10/2026

| ID     | Decisão                                                                                                                                        |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| IA-A01 | Evoluir o bloco IA existente; permissões de mídias, tags e campos opcionais.                                                                   |
| IA-A02 | Leitura integral de tags e campos; privacidade orientada apenas por prompt, sem classificação ou filtro automático de saída.                   |
| IA-A03 | Até 20 mídias de 20 MiB por bloco, formatos do editor, sem legenda.                                                                            |
| IA-A04 | Uma tentativa por mídia/sessão, inclusive em falha; nenhum reenvio mesmo após pedido do cliente. Nova entrada reinicia o controle.             |
| IA-A05 | Falha ou envio incerto mantém a conversa em texto e exige informar ausência de confirmação.                                                    |
| IA-A06 | Adicionar somente tags selecionadas; preencher somente campos autorizados. Sobrescrita começa desativada; se permitida, última gravação vence. |
| IA-A07 | Limite técnico aprovado no plano: 20 chamadas de ferramentas e 150 segundos por turno; esgotamento encaminha ao humano.                        |

Implementação e cenários em [Assistente IA](../modulos/bloco-assistente-ia.md). Após cadastro da chave, três modelos, 20 verificações integradas com Sol e cinco modalidades de entrada foram homologados em APIs reais. Alterações de CRM ficaram em memória; envios reais usaram o número de teste indicado pelo responsável.

## Dependências conhecidas

- Frontend Vue e integrações implementados; cada instalação precisa de credenciais e servidor UAZAPI próprios. Testes reais parciais estão registrados por recurso.
- Avatares, QR Code, envio e renovação de mídias precisam de validação no servidor contratado.
- Finalização libera o responsável e o bloqueio humano; não remove pausa manual nem retoma execução interrompida. Não existe preferência automática pelo último atendente.
- Aguardar Resposta grava texto aceito no campo personalizado escolhido, substituindo o valor anterior; esse dado de CRM não constitui histórico completo de mensagens.
- Ler a documentação pública não valida automaticamente a versão do servidor contratado.

## Histórico

- **2026-10-12:** B-23 e plano de voz aprovados para implementação: `ptt` via backend, OGG/Opus temporário, sem conteúdo persistido ou reenvio automático; implementados e testados localmente, aceite real de áudio e dispositivos pendente.

- **2026-10-12:** primeira entrega visual de fluxos implementada com biblioteca simples, canvas Vue Flow, rascunhos, texto no bloco Conteúdo e permissão granular por workspace. Publicação e execução seguem pendentes.

- **2026-10-12:** decisões B-01 a B-04 confirmadas e documentos organizados.
- **2026-10-12:** B-05 confirmada em resposta explícita: liberar responsável e preservar histórico.
- **2026-10-12:** identificada retenção limitada da UAZAPI; revisão da política de histórico apresentada como próxima decisão pendente.
- **2026-10-12:** B-06 confirmada: manter somente na UAZAPI e aceitar histórico limitado. Próximo ponto: nova mensagem após finalização.

- **2026-10-12:** B-07 a B-11 confirmadas: gatilho humano, atores das ações, seletores, campos e anexos/busca. Estrutura mobile apresentada como proposta, aguardando decisão.

- **2026-10-12:** B-12 esclarecida: mesma aplicação web em desktop/celular, sem frontend mobile independente. Instalação PWA permanece proposta opcional.
- **2026-10-12:** B-13 a B-18 detalhadas e implementadas na primeira interface do chat: ação manual, permissão de não atribuídos, resposta do administrador, semântica dos status, notas separadas e busca por nome/telefone.
- **2026-10-12:** B-19 a B-22 confirmadas: página WhatsApp para administradores, perfil fixo no desktop, prévia imediata de imagens e avatares de leads.
- **2026-10-12:** B-24 confirmada: bolinha para não lidas, leitura compartilhada ao ver as mensagens recentes e painel fixo do chat com rolagem no histórico.

## Sequências — decisões de 14/10/2026

Regras **SQ-01 a SQ-13** aprovadas e consolidadas em [Sequências](../modulos/sequencias.md#2-decisões-funcionais-aprovadas): fluxo exclusivo, etapas sequenciais, intervalo após o término anterior, pausa de saldo, data fixa, uma participação vigente, substituição preservando o fluxo corrente, reentrada ignorada e inclusão por Operações ou contato.

Políticas complementares aprovadas: interrupções liberam próxima etapa após os bloqueios; falha definitiva encerra participação; saltos pertencem à mesma etapa até o fim da cadeia; versões imutáveis da sequência e dos fluxos fixadas na publicação; desativação bloqueia apenas novas entradas; primeira reserva transacional vence; atendimento humano bloqueia desde a abertura. Intervalos de 1 minuto a 365 dias, scheduler de um minuto e data única com fuso explícito.

Gestão e inclusão usam `canManageFlows`; inclusão/consulta exigem visibilidade da conversa. Interface linear com rascunho/publicação aprovada. Homologação real executou duas etapas na UAZAPI; normalização brasileira do contato exige confirmação por identificador único do envio, sem filtrar pelo chat original.

## Webhooks externos — 14/10/2026

Módulo de entrada separado da UAZAPI, administrado por proprietários/administradores. Processamento síncrono, telefone internacional com DDI (também dígitos), URL estável sem autenticação adicional e último exemplo criptografado mantido até substituir/apagar. Um objeto por evento, com arrays internos e índices fixos; igualdade tipada, regras em ordem e última ação de tag prevalecendo. Sem deduplicação de eventos, exclusão, regeneração de URL ou disparo de automações. Contrato e arquitetura em [Webhooks Externos](../modulos/webhooks-externos.md).

## Assistente IA de Fluxos — decisões aprovadas em 2026-10-14

- Entregar o MVP completo com janela flutuante, adaptada ao celular.
- Iniciar a construção somente após aprovação do plano no botão Construir.
- Compartilhar a prévia temporária entre os editores do mesmo fluxo; salvar ou descartar afeta toda a equipe.
- Salvar substitui o rascunho salvo. Descartar exige confirmação e retorna ao último salvamento. Não oferecer desfazer.
- Recuperar trabalho em recarga/fechamento acidental; executar descarte nas saídas confirmadas pela aplicação quando possível.
- Pausar edição manual e salvamento durante a IA; Parar preserva os lotes temporários concluídos.
- Preservar histórico, permissões separadas, chave própria do workspace, isolamento e publicação manual conforme o [contrato implementado](../modulos/assistente-ia-fluxos.md).

## 14/10/2026 — Bloco Condição ampliado e exclusão protegida

- Condição combina etiquetas e campos de texto com um operador geral E/OU; sem grupos aninhados ou operadores adicionais.
- Configuração aprovada em cartões numerados no painel esquerdo, mantendo Sim/Não e conexões estáveis.
- Assistente IA do editor cria, edita e conecta condições usando definições do workspace; não consulta valores reais de contatos.
- Aprovado incluir Excluir no cadastro de campos/etiquetas. Exclusão física com confirmação: campo e valores são apagados; etiqueta e vínculos são removidos.
- Backend protege versões publicadas, execuções ativas e versões preservadas por sequências; cobre referências dos blocos existentes e webhooks ativos. Rascunhos não bloqueiam.
- Contrato mantém schema 2, `condition_operator` e `checks`; dados legados são normalizados sem reescrever versões publicadas.
- Implementação e critérios detalhados em [Bloco Condição](../modulos/bloco-condicao.md). Verificação automatizada usa dados sintéticos e respostas de IA simuladas, sem consumo de APIs externas.

## Documentos relacionados preservados

Consulte também a [versão alternativa com decisões do Menu](../historico/decisoes-menu.md). A organização dos arquivos não consolida automaticamente diferenças de conteúdo entre as versões.

## Menu — decisões e implementação de 14/10/2026

Consolida B-13 a B-15 preservadas no histórico: um bloco com Botões, Lista e Numérico, uma saída por opção e somente Continuar por prazo/erros; escolha interativa real em Botões/Lista, número válido no Numérico e fallback somente após falha comprovada do transporte interativo.

Confirmado nesta rodada: Numérico inicial; espera de 5 minutos, ajustável de 1 minuto a 7 dias; limite de 3 erros, ajustável de 1 a 10; mensagem de erro editável e repetição das opções sem renovar o prazo; Lista simples; suporte ao Assistente IA do editor. Implementado e testado localmente; formatos interativos bloqueados até homologação por conexão. Ver [Bloco Menu](../modulos/bloco-menu.md).

## 15/10/2026 — Importação e exportação de fluxos

- JSON CHAIT 1.0 como transporte de um fluxo por arquivo, independente do grafo interno versão 2.
- Importar somente no editor; confirmar substitui e salva o rascunho e a prévia compartilhada, mantendo identidade, nome, pasta, publicação e gatilhos. Sem prévia visual, backup ou desfazer específico.
- Permissão de gerenciar fluxos permite reutilizar/criar etiquetas e campos pelo importador, como já ocorre no editor. Não exige permissão de IA nem chave.
- Bloquear durante IA ativa. Importação invalida plano e geração anteriores, preserva histórico e exige novo plano.
- Arquivos, atendentes, outros fluxos e sequências não são associados automaticamente. Incompletude pode ser salva; publicação segue as regras normais.
- Recursos autorizados do bloco IA seguem a associação/criação de etiquetas/campos e mídias pendentes; preservar textos e limites numéricos, repor opções gerais e sobrescrita aos padrões.
- Exportar rascunho efetivamente salvo ou versão publicada. Guia Markdown atualizado, sem instruções de criação de bloco IA.
- Contrato e critérios em [Importação e exportação](../modulos/importacao-exportacao-fluxos.md).

## 15/10/2026 — Campos do contato nos fluxos

Implementado ContactTemplate v1 dentro do grafo v2, com chips por ID, edição leve, clipboard isolado por workspace, resolução consistente por mensagem e snapshot atualizado por chamada IA. Textos legados e chaves literais não são convertidos. Resposta/Menu/Operações/Condição/recursos IA não recebem chips. Conteúdo continua após inválidos ou recusas; IA segue Continuar para prompt inválido/falha do envio. Incerteza usa intenção persistida e conciliação, sem reenvio/recálculo; esgotamento fica desconhecido. Eventos seguros são consultáveis no editor. Publicação/envio com chips dependem de homologação da conexão por falta de escape UAZAPI comprovado. Ver [contrato](../modulos/campos-do-contato-fluxos.md). [Notificações internas](notificacoes-operacionais-fluxos.md) ficam fora da entrega.

## Revisão aprovada em 17/10/2026 — operação por novas mensagens

Nos dois modos, a lista vem exclusivamente do banco local. Contatos e conversas WhatsApp são descobertos somente por mensagens recebidas via webhook após o marco de ativação da conexão; não há importação inicial, varredura geral, reconciliação periódica nem recuperação retrospectiva de webhooks perdidos. Conexões existentes recebem o marco na implantação e mantêm seus registros anteriores. Histórico é consultado apenas por conversa conhecida. A prévia textual de até 200 caracteres permanece como exceção aprovada à regra de não persistir conteúdo integral de mensagens. [Especificação](../ajuste-integracao-uazapi-sem-sincronizacao.md).

## Revisão aprovada em 16/10/2026 — volume e tempo real

A decisão B-06 passa a permitir uma prévia textual de até 200 caracteres por conversa e conteúdo temporário criptografado necessário ao processamento assíncrono. O histórico completo continua no provedor; jobs e logs contêm apenas referências. Conteúdo temporário é apagado após conclusão dos consumidores e expira em até 24 horas em falha/pendência. Mídias ficam temporariamente no disco privado quando necessárias ao processamento, sem base64 persistido.

A lista vem do banco do CHAIT e é atualizada por webhooks; a importação progressiva descrita na revisão de 16/10 foi substituída pela decisão de 17/10. Laravel Reverb distribui eventos apenas aos usuários autorizados. Histórico é consultado somente ao abrir a conversa, com eventos posteriores na memória da tela. IndexedDB, sincronização completa periódica e recuperação automática de webhooks não recebidos ficam fora desta entrega. [Contrato operacional atualizado](../operacao/chat-realtime.md).

## Revisão documental de 16/10/2026 — estado observado e ajustes recentes

Esta revisão sincroniza os documentos com o código e não executa nova homologação nem aprova retroativamente diferenças de produto.

- Novas sequências usam `America/Sao_Paulo`, independentemente do fuso do workspace; existentes preservam o valor salvo e permitem alteração pelo seletor. Padrão solicitado e implementado nesta sessão.
- Formulários de configurações e ações Editar/Excluir nas listas de campos/tags ficam à direita; Remover nas palavras-chave e os campos nome/fuso de Sequências tiveram alinhamento corrigido. Detalhes no [guia visual](../interfaces/design-interface-chait.md#alinhamentos-revisados-em-16102026).
- O breakpoint atual do chat é 1024 px, com lista de 280 px, conversa mínima de 360 px e perfil de 290 px no desktop, sujeito à adaptação do espaço disponível.
- **Diferenças observadas frente ao recorte original:** Abertos inclui `open` e `in_progress`; Concluídos permanece aceito na API, mas não no seletor atual; o workspace é escolhido no rodapé do menu compartilhado, inclusive mobile; o formulário de notas aparece no perfil mobile. Essas observações descrevem implementação e não são novas aprovações das decisões anteriores.
- Campos personalizados em rascunhos salvos e prévias compartilhadas também bloqueiam exclusão; etiquetas referenciadas somente nesses rascunhos/prévias não recebem esse bloqueio. Versões em uso e webhooks ativos mantêm suas proteções.
- Playwright usa schema descartável PostgreSQL em `chait_test`. O Assistente IA de Fluxos usa `ai` com tempo real habilitado e `default` no caminho anterior; progresso do assistente continua por consultas periódicas.

O roteiro original e as versões em `historico/` preservam seu contexto. O índice e os contratos vigentes devem ser consultados para o comportamento atual.

## Liberação dos recursos de fluxos — 17/10/2026

Por solicitação explícita do responsável, botões URL/Resposta/Copiar, menus Botões/Lista e campos dinâmicos estão liberados em todas as conexões, inclusive produção, sem perfis de homologação. Esta decisão substitui as exigências de liberação por conexão registradas anteriormente. A validação real em produção será feita pelo responsável. Permanecem as validações da aplicação e a incompatibilidade conhecida de substituições nativas da UAZAPI em texto/legenda; os registros dos ensaios anteriores são preservados.
