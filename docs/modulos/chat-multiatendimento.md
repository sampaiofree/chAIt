# Especificação funcional e técnica — Chat e Multiatendimento

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Projeto:** SaaS de atendimento multicanal (Laravel)  
> **Módulo:** Chat e multiatendimento — primeira entrega via WhatsApp  
> **Documento:** `docs/modulos/chat-multiatendimento.md`  
> **Versão:** 0.8 (voz e leitura compartilhada)  
> **Data:** 2026-10-12  
> **Status:** base implementada, integrada a Fluxos e Sequências; tempo real sob configuração. Regras atuais, decisões anteriores e validações operacionais são distinguidas neste documento.  
> **Interface:** [Desktop e mobile](../interfaces/chat.md).  
> **Público-alvo:** Desenvolvimento backend, frontend, aplicativo móvel, QA, produto e operações.

## 1. Objetivo e escopo

Construir um **SaaS multicliente** com interface de atendimento inspirada na organização por _workspace_ do BotConversa e possibilidade de expansão para recursos semelhantes aos do ManyChat.

A **primeira entrega aprovada** é um chat multiatendimento para WhatsApp integrado inicialmente à **UAZAPI**. O sistema será desenvolvido em **Laravel**, com a mesma aplicação web adaptada para desktop e celular, e deverá estar preparado para novos canais (por exemplo, Instagram Direct) **sem implementar esses canais agora**.

### 1.1 Direção confirmada em 12/10/2026

- **Provedor:** UAZAPI, com [documentação oficial](https://docs.uazapi.com/) e [registro de validação](../integracoes/uazapi.md).
- **Limite inicial:** um número de WhatsApp por workspace.
- **Frontend web:** Vue 3 + Inertia.js; Vue Flow é usado no editor de fluxos já implementado.
- **Prioridade:** chat e multiatendimento antes do editor e do executor de automações.
- **Finalização:** liberar o responsável atual e preservar o último atendente e o histórico operacional.
- **Situação do código:** a base Inertia foi migrada para Vue 3; a interface inicial e os endpoints do chat estão implementados. Há testes reais parciais de integração registrados nos módulos; eles não equivalem à homologação completa do atendimento ou do tempo real.

### 1.2 Escopo da primeira etapa

- Workspaces independentes, cada um com seu próprio WhatsApp.
- Usuários com login global e acesso a um ou mais workspaces autorizados.
- Contatos separados por workspace.
- Listagem e leitura de conversas e mensagens do WhatsApp por integração com a UAZAPI.
- Envio e recebimento de mensagens; anexos iniciais: **documento, imagem e vídeo**, com suporte operacional a validar na UAZAPI.
- Campo de busca na coluna de conversas por nome e telefone do contato.
- Dois filtros em listas suspensas: atribuição **Todos / Meus chats / Não atribuídos** e status **Todos / Abertos**. A decisão inicial incluía Concluídos, mas a tela atual não oferece essa opção; ver seção 6.4.
- Cadastro inicial do contato com **nome, email e CPF**; notas internas aparecem no perfil da conversa.
- Atribuição pelo primeiro envio humano ou por ação explícita no perfil.
- Regras de visibilidade por usuário.
- Transferência imediata de atendimentos mediante permissão.
- Notas internas vinculadas à conversa.
- Separação entre estado do atendimento humano e estado das automações.

### 1.3 Fora do escopo desta etapa

- O editor e a execução de fluxos ficaram fora da primeira entrega histórica do chat, mas já estão implementados e integrados; seu contrato está no [Editor de fluxos](editor-de-fluxos.md).
- Distribuição automática de conversas, rodízio, balanceamento e atribuição por fluxo.
- Evoluções das regras de reentrada: o comportamento implementado após finalização e nova mensagem está nas seções 6 e no contrato de Fluxos.
- Implementação de Instagram Direct, automação de comentários e verificação de seguidores.
- Definição completa do CRM; apenas a base de contatos necessária para o chat entra agora.
- Regras comerciais detalhadas de assinatura, pagamento e limites de plano.

> **Princípio:** decisões relativas a fluxos não devem ser implementadas por suposição nesta etapa.

## 2. Conceitos e isolamento de dados

| Conceito               | Definição aprovada                                                                                                          |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| **Workspace**          | Tenant principal e ambiente isolado do SaaS. Não haverá uma entidade `empresa` acima dele.                                  |
| **Licença**            | Comercializada por workspace. Contratar outro número significa contratar outro workspace.                                   |
| **Usuário**            | Identidade global: um login pode acessar vários workspaces.                                                                 |
| **Vínculo do usuário** | Associação do usuário com um workspace, com permissões independentes em cada um.                                            |
| **Canal/conexão**      | Integração técnica do workspace com um provedor; inicialmente, um WhatsApp via UAZAPI.                                      |
| **Contato**            | Cadastro pertencente exclusivamente a um workspace. Mesmo número em workspaces distintos corresponde a cadastros separados. |
| **Conversa**           | Referência operacional do atendimento no workspace, não uma cópia persistida do histórico de mensagens do WhatsApp.         |

### 2.1 Regras obrigatórias de isolamento

1. Cada workspace começa com **exatamente um número de WhatsApp configurável**; múltiplos canais no mesmo workspace não estão aprovados para a primeira versão.
2. Todo cadastro, permissão, nota e atendimento pertence a um workspace.
3. Um usuário pode alternar entre workspaces aos quais tenha acesso sem criar logins adicionais.
4. Contatos **não são compartilhados** entre workspaces, mesmo quando o número de telefone coincide.
5. Todas as consultas e operações da API devem impor o contexto do workspace no **backend**; ocultar elementos no frontend não constitui autorização.
6. Identificadores fornecidos pelo cliente, incluindo `workspace_id`, `contact_id` ou `conversation_id`, devem ser validados quanto à pertença ao workspace atual.
7. Tokens e credenciais dos provedores devem ser segregados por conexão/workspace, protegidos e nunca enviados ao frontend.

**Decisão arquitetural:** manter a entidade lógica _canal/conexão_ separada do workspace. Isso evita acoplamento direto à estrutura da UAZAPI e prepara integrações futuras. Não implica que múltiplos canais por workspace já estejam no escopo.

## 3. Persistência: o que salvar e o que não salvar

### 3.1 Política vigente e modos de operação

A UAZAPI é a fonte do **histórico completo disponível**. A decisão B-06 de 12/10/2026 foi revisada em 16/10/2026 para permitir prévias locais e conteúdo temporário; não autoriza criar uma cópia permanente do histórico.

O Laravel mantém workspaces, credenciais criptografadas, vínculos/permissões, contatos e valores do CRM, atribuições, estados, notas internas e metadados operacionais. `Aguardar Resposta` pode gravar texto aceito no campo personalizado escolhido; isso é captura funcional do CRM.

| Modo                                              | Lista e atualização                                                                                             | Conteúdo no backend                                                                                                                                                                                                  |
| ------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CHAIT_REALTIME_ENABLED=false` (padrão do código) | Consulta/sincronização de chats pela UAZAPI e atualização periódica da interface.                               | Caminho anterior, sem caixa de entrada durável de corpos.                                                                                                                                                            |
| `CHAIT_REALTIME_ENABLED=true`                     | Lista local, importação progressiva e eventos autorizados via Reverb; histórico consultado ao abrir a conversa. | Prévia textual de até 200 caracteres por conversa e payloads temporários criptografados em `uazapi_inbox`/`chat_operations`; exclusão após consumidores concluídos e retenção máxima de 24 horas em falha/pendência. |

Arquivos necessários ao processamento assíncrono podem permanecer temporariamente no disco privado, com o mesmo limite; base64 não é persistido. Jobs levam referências, não corpos. Logs não devem conter mensagens. A retenção da base ativa não elimina cópias em backups anteriores.

O novo caminho está implementado sob configuração e exige ativação/validação de infraestrutura. O contrato completo fica em [Chat em tempo real](../operacao/chat-realtime.md). A retenção limitada e a recuperação sem garantia do provedor continuam aplicáveis.

### 3.2 Implicações e riscos

- **Dependência do provedor:** se a UAZAPI estiver indisponível, limitada ou não retornar parte do histórico, o chat pode não conseguir exibir mensagens antigas.
- **Disponibilidade e ordenação:** carregamento inicial, paginação, busca, histórico e sincronização precisam ser testados na API real. Não presumir que todo tipo de consulta ou recuperação histórica esteja disponível.
- **Notas internas:** a tela atual mostra o histórico de notas no perfil do contato, separado das mensagens; não são enviadas ao WhatsApp.
- **Informações necessárias para operar:** guardar referências externas e estados internos não equivale a armazenar o conteúdo das mensagens.
- **Mobile e notificações:** a bolinha e a leitura compartilhada acompanham o modo ativo (consultas periódicas ou eventos Reverb). Notificações push não são concedidas pela existência do tempo real.

> **Pendência técnica bloqueadora:** validar com a documentação e testes da **versão exata da UAZAPI contratada** a consulta de histórico, identificadores estáveis, paginação, mídias, webhooks, autenticação, limites, períodos de retenção e comportamento em reconexões. A implementação não deve prometer capacidades não confirmadas.

## 4. Acesso e visibilidade das conversas

O administrador define as permissões no **cadastro/vínculo do usuário ao workspace**.

| Permissão                        | Comportamento                                                           |
| -------------------------------- | ----------------------------------------------------------------------- |
| **Ver todas as conversas**       | Pode visualizar conversas do workspace independentemente de atribuição. |
| **Ver minhas conversas**         | Pode visualizar conversas atribuídas a si.                              |
| **Ver conversas não atribuídas** | Pode visualizar conversas sem responsável.                              |

**Modelo de briefing:** caixas combináveis para Todas/Minhas/Não atribuídas. **Implementação atual:** `workspace_memberships` oferece `can_view_unassigned`, `can_manage_flows` e `can_use_flow_assistant`, além do papel. Dono/admin ou membro com `can_view_unassigned=true` têm acesso amplo às conversas do workspace; sem essa permissão, o membro vê somente as atribuídas a si. Portanto o nome da permissão não restringe a consulta somente às não atribuídas, e não há três checks de visibilidade independentes na tela atual. Os seletores da lista apenas filtram esse conjunto autorizado. Esta descrição registra o código, sem aprovar retroativamente a diferença em relação ao briefing.

**Observação:** _visualizar_ não significa automaticamente _poder responder_, _transferir_ ou _assumir_. As ações devem ter autorização própria.

### 4.1 Matriz mínima de acesso

| Ação                                   | Regra aprovada                                                                                                                                                            |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Ler conversa                           | Exige visibilidade conforme permissões do vínculo no workspace.                                                                                                           |
| Assumir conversa | Quem pode visualizar pode acionar **Assumir para mim** no perfil, inclusive quando há outro responsável. A troca é explícita e auditada. |
| Enviar resposta humana | Em conversa sem responsável, o primeiro envio abre e atribui ao remetente. Em conversa ocupada por outra pessoa, o envio é bloqueado inclusive para administradores. |
| Finalizar atendimento                  | Atendente atribuído ao lead/conversa ou administrador do workspace.                                                                                                       |
| Escolher outro responsável | Somente administrador pode atribuir a outro membro do mesmo workspace pelo perfil. |
| Ler notas internas                     | Somente usuários autorizados a visualizar a conversa no workspace.                                                                                                        |
| Criar nota interna                     | Atendente atribuído ao lead/conversa ou administrador do workspace. Edição e exclusão continuam pendentes.                                                                |

## 5. Atribuição manual e transferências

### 5.1 Assumir atendimento

1. Uma conversa sem responsável aparece aos usuários com permissão para visualizá-la.
2. O primeiro envio de texto, anexo ou áudio abre e atribui a conversa ao remetente. **Assumir para mim** no perfil faz a mesma transição sem enviar mensagem. **Abrir atendimento** no perfil abre sem atribuir.
3. O Laravel verifica workspace, permissão, status e atribuição atual.
4. O Laravel atribui a conversa **atomicamente**. O primeiro envio não toma uma conversa ocupada; quem pode visualizá-la pode transferi-la explicitamente para si no perfil. Administradores também podem escolher outro membro do workspace.
5. A aplicação registra quem assumiu e quando; libera a resposta humana para o responsável.

**Implementação:** transação com bloqueio do contato; envio concorrente perde com HTTP `409` se outro usuário assumiu primeiro. A interface atualiza a conversa.

**Fora deste fluxo:** rodízio e distribuição por fluxos continuam para decisão futura.

### 5.2 Transferência explícita

- **Assumir para mim** transfere imediatamente uma conversa visível ao usuário, mesmo que outro membro seja o responsável. Nunca ocorre pelo simples envio.
- O administrador pode escolher outro membro ativo do workspace como responsável. Atendentes comuns não escolhem terceiros.
- A troca libera o registro de atribuição anterior, cria o novo registro e grava o ator na auditoria. Quem só pode ver as próprias conversas perde visibilidade quando deixa de ser responsável.
- O comportamento anterior de permissão `can_transfer` por vínculo fica como histórico de briefing; esse campo não existe na implementação atual.

## 6. Atendimento humano versus automações

### 6.1 Separação lógica aprovada

Há dois conceitos distintos:

1. **Controle/atividade do atendimento humano:** indica se uma conversa está sendo conduzida por pessoa.
2. **Status do atendimento humano:** representa seu estágio operacional.

Os status propostos e aceitos para o atendimento humano são:

| Status de negócio  | Significado pretendido                                       |
| ------------------ | ------------------------------------------------------------ |
| **Aberto**         | Atendimento humano solicitado/aberto, ainda não assumido.    |
| **Em atendimento** | Atendimento humano assumido e sendo conduzido por atendente. |
| **Finalizado**     | Atendimento humano encerrado.                                |

**Regra aprovada:** enquanto estiver em **Em atendimento**, a automação deve ficar pausada para a conversa/contato — inclusive gatilhos por palavra-chave — e não interferir na resposta do atendente.

**Regras confirmadas para a primeira versão:**

- Uma nova mensagem **recebida** não abre nem reabre atendimento humano por si só. A primeira resposta **enviada por atendente** abre, atribui e pausa a automação. O botão **Abrir atendimento** no perfil continua disponível para abertura sem envio e sem atribuição.
- Um membro autorizado a ver conversas não atribuídas pode abrir o atendimento. O administrador define essa permissão no vínculo do usuário ao workspace.
- Conversas sem responsável podem ser respondidas diretamente por quem tem acesso; o envio assume o atendimento. Conversas ocupadas exigem troca explícita de atribuição no perfil, mesmo para administradores.
- Fora do atendimento humano, a mensagem segue o processamento normal de palavras-chave e fluxos. Finalizar libera o bloqueio humano para novos gatilhos, mas não retoma execuções interrompidas nem remove pausa manual. Sequências preservam seus estados conforme o [contrato próprio](sequencias.md).
- Ao finalizar, remover a atribuição ativa, preservar o último responsável e registrar quem finalizou e quando. A finalização não apaga notas nem o histórico operacional e não transfere automaticamente a conversa a outra pessoa.
- O último responsável é preservado como histórico; não há preferência automática por ele na nova atribuição.
- O bloqueio humano começa na abertura, inclusive no status **Aberto**, antes de assumir. A abertura interrompe a execução ativa e bloqueia novos gatilhos e etapas de Sequências.

**Requisito de compatibilidade futura:** preservar a identidade do último responsável e o histórico operacional de atendimento, mesmo quando um atendimento é finalizado.

> **Nota de modelagem:** o número e formato exatos dos campos (`human_active`, `human_status`, etc.) são **proposta técnica**, não decisão de esquema. Evitar campos redundantes ou estados contraditórios; definir a máquina de estados e as restrições antes das migrations.

### 6.2 Finalização aprovada

Ao finalizar um atendimento:

1. Alterar o status humano para **Finalizado**.
2. Liberar o responsável atual; ele deixa de ter uma atribuição ativa nessa conversa.
3. Preservar o último responsável e os eventos do atendimento.
4. Registrar autor e horário da finalização.
5. Atualizar a visibilidade conforme as permissões. A conversa deixa de corresponder ao filtro **Meus chats** por atribuição ativa; o filtro **Não atribuídos** seleciona ausência de responsável ativo, incluindo finalizadas quando o filtro de status não as excluir.

**Comportamento atual:** não há preferência automática pelo último atendente. Finalizar libera a pausa humana; pausas manuais são independentes e nenhuma execução interrompida é retomada por essa ação. As regras de Sequências são aplicadas pelo gerenciador central.

### 6.3 Entrada no atendimento humano e mensagens após finalização

**Decisão aprovada:** a conversa só é aberta para atendimento humanizado por uma ação que determine essa abertura: primeiro envio humano, abertura manual no perfil ou gatilho de fluxo. A simples chegada de mensagem, inclusive depois de **Finalizado**, não inicia atendimento humano.

Sem esse gatilho, a conversa tem o comportamento normal do chat: avaliação de palavras-chave, fluxos e demais regras aplicáveis, observando o estado e as pausas existentes. A prioridade detalhada dos gatilhos está na [especificação de fluxos](editor-de-fluxos.md). Esta decisão não autoriza reativar uma pausa manual nem retomar uma execução interrompida automaticamente.

**Mecanismos implementados:** primeiro envio humano, botão manual **Abrir atendimento** no perfil, operação de abertura humana nos fluxos e encaminhamento do bloco IA. A mensagem recebida sozinha não abre atendimento humano.

### 6.4 Filtros e cadastro inicial

- Atribuição na tela: **Todos**, **Meus chats**, **Não atribuídos**. Todos não amplia as permissões do backend; Meus chats usa atribuição ativa e Não atribuídos usa responsável nulo.
- Status na tela atual: **Todos** e **Abertos**. No backend, `status=open` inclui `open` e `in_progress`, portanto também atendimentos aguardando responsável. Os dois caminhos do chat ainda aceitam `status=completed` pela API, mas a opção Concluídos não está no seletor atual.
- A decisão anterior de Abertos somente em atendimento e a opção Concluídos são registros do recorte original; a revisão descreve a diferença observada, sem atribuir aprovação retroativa à mudança.
- Nome, e-mail e CPF são opcionais no endpoint de edição do perfil. Nome aceita até 255 caracteres; e-mail deve ser válido, até 255; CPF é string de até 14 caracteres, sem validação de dígitos verificadores nesse endpoint. Não há unicidade de e-mail/CPF nessa edição.
- O telefone identifica o contato por workspace, com normalização/unicidade descritas em [Webhooks Externos](webhooks-externos.md). O perfil edita apenas nome/e-mail/CPF; notas internas pertencem à conversa, e o campo legado de notas gerais do contato não é enviado por esse formulário.

## 7. Notas internas

**Funcionalidade aprovada para a primeira etapa.**

- Notas vinculadas à conversa e ao workspace.
- Autor e horário registrados.
- Criação autorizada ao atendente atribuído ao lead/conversa e ao administrador do workspace.
- Visíveis somente à equipe com acesso à conversa.
- **Nunca** enviadas ao WhatsApp/UAZAPI como mensagens.
- Persistidas no banco da aplicação, de forma distinta do histórico externo do WhatsApp.
- Disponíveis a novos responsáveis após transferência, respeitadas as permissões.

**Pendências de produto:** se notas podem ser editadas/excluídas, anexos em notas, menções, notificações e trilha de auditoria para alterações.

## 8. Proposta de arquitetura Laravel (não é esquema aprovado)

### 8.1 Componentes

- **Laravel API/backend:** autenticação, autorização, workspaces, contatos, atribuições, status, notas e auditoria.
- **Adaptador de canal** (`ChannelProvider` / `WhatsAppProvider`): encapsula consultas/envios do WhatsApp, evitando espalhar chamadas UAZAPI pelo domínio.
- **UAZAPI:** provedor inicial para mensagens, mídias e eventos do WhatsApp.
- **Filas Laravel + Horizon:** processamento assíncrono de webhooks, normalização de eventos, reconciliação e notificações.
- **Atualização:** consultas periódicas no caminho anterior; Laravel Reverb no modo de tempo real, conforme [contrato operacional](../operacao/chat-realtime.md).
- **Clientes web e mobile:** consomem a API de domínio; não acessam credenciais da UAZAPI diretamente.

### 8.2 Entidades candidatas

| Entidade/tabela sugerida | Responsabilidade                                       | Observação                                                      |
| ------------------------ | ------------------------------------------------------ | --------------------------------------------------------------- |
| `users`                  | Identidade e autenticação globais                      | Login único.                                                    |
| `workspaces`             | Unidade comercial e tenant                             | Raiz do isolamento.                                             |
| `workspace_user`         | Associação e permissões do usuário por workspace       | Papel pode variar entre workspaces.                             |
| `channel_connections`    | Provedor, referência externa e estado da conexão       | Um WhatsApp por workspace no início.                            |
| `contacts`               | Cadastro e atributos do contato                        | Chave sempre inclui o workspace.                                |
| `conversations`          | Referência externa, status humano e atribuição vigente | **Sem texto de mensagens.**                                     |
| `conversation_events`    | Auditoria de assumir, transferir, finalizar            | Apenas metadados de operação.                                   |
| `internal_notes`         | Texto das notas da equipe                              | Conteúdo é interno, não mensagem WhatsApp.                      |
| `webhook_receipts`       | Deduplicação/idempotência de eventos externos          | Guardar apenas dados necessários; evitar payloads com mensagem. |

Uma estrutura de sessões de atendimento humano (por exemplo, `human_sessions`) pode ser necessária para preservar atendimentos sucessivos; decidir após a definição completa da máquina de estados.

### 8.3 Integração: comportamento mínimo recomendado

- Validar autenticação/assinatura dos webhooks conforme recursos do provedor e impor controles contra uso indevido.
- Identificar **a conexão** que recebeu o evento e derivar seu workspace no backend; não confiar em `workspace_id` vindo do payload como autorização.
- Tratar eventos duplicados de forma **idempotente**.
- Prever eventos atrasados/fora de ordem e reconciliação ao reconectar.
- Não permitir que tarefas atrasadas provoquem transferência ou mudança de status indevida.
- Revalidar permissões e atribuição **antes** de enviar uma resposta.
- Respeitar a política da seção 3: histórico completo fora da base local, prévias e conteúdo temporário limitados no modo de tempo real, jobs por referência e ausência de corpos em logs/monitoramento.
- Aplicar escopo de workspace também em broadcasts, cache, pesquisas e notificações.
- Testar reconexão e expiração de URLs de mídia; não presumir retenção indefinida no provedor.

### 8.4 Estratégia de autorização recomendada

Usar Policies/Gates e serviços de aplicação centralizados para ações como:

- `viewConversation(user, conversation, workspace)`
- `claimConversation(user, conversation)`
- `replyToConversation(user, conversation)`
- `transferConversation(user, conversation, destinationUser)`
- `viewInternalNotes(user, conversation)`

A seleção de workspace na interface **não altera** o limite de autorização do backend.

## 9. Fluxos funcionais de referência

As ações de atribuição implementadas estão descritas na seção 5.2.

### 9.1 Novo contato e mensagem recebida

1. UAZAPI notifica evento ao endpoint de integração (formato e evento a validar).
2. O backend identifica a conexão, o workspace e o contato correspondente; cria/atualiza o cadastro do contato dentro **daquele** workspace.
3. O sistema atualiza os metadados operacionais; no modo de tempo real, também conserva a prévia limitada e o conteúdo temporário permitido na seção 3.
4. O frontend consulta o histórico de mensagens no provedor por meio do adaptador/backend.
5. **Não atribuir automaticamente** um atendente nesta fase.
6. Se já existe atendimento humano **Em atendimento**, os fluxos automáticos ficam pausados conforme a regra aprovada.
7. Após **Finalizado**, uma mensagem segue o processamento normal de palavras-chave/fluxos quando disponíveis; só abre atendimento humano se passar por gatilho definido para isso. A chegada da mensagem não reabre automaticamente o atendimento.

### 9.2 Atendimento assumido

1. Usuário vê uma conversa não atribuída (se autorizado).
2. Envia a primeira resposta ou seleciona **Assumir para mim** no perfil.
3. Backend abre, atribui atomicamente, pausa a automação e registra o evento.
4. Usuário passa a ser responsável.

### 9.3 Transferência

1. Administrador seleciona outro usuário do workspace, ou usuário com acesso escolhe **Assumir para mim**.
2. Backend valida permissão e destino.
3. Backend altera responsável imediatamente e audita operação.
4. Interfaces dos envolvidos atualizam visibilidade e notificação.

### 9.4 Nota interna

1. Usuário autorizado adiciona uma nota.
2. Backend persiste texto da nota no workspace/conversa e autor.
3. Nota aparece apenas na interface interna para quem tem acesso.
4. Não ocorre envio da nota para a UAZAPI.

## 10. Critérios de referência e verificação

Os checks preservam registros anteriores; não representam execução de testes nesta revisão documental. Filtros, permissões e persistência devem ser interpretados pelas seções 3, 4 e 6.4.

- [ ] Usuário com acesso a dois workspaces alterna entre eles sem visualizar contatos ou conversas não autorizados de outro workspace.
- [ ] Um mesmo telefone pode existir como contato em dois workspaces, sem compartilhamento de dados.
- [ ] Conferir a autorização atual por papel, `can_view_unassigned` e atribuição na listagem e nos detalhes, distinguindo-a do modelo de três checks do briefing.
- [ ] Primeiro envio por atendente com acesso abre e atribui conversa sem responsável; envio em conversa ocupada não transfere, inclusive para administrador.
- [ ] Dois atendentes tentando enviar primeiro não conseguem ambos obter a atribuição.
- [ ] Transferência autorizada muda imediatamente o responsável, registra auditoria e atualiza a visibilidade.
- [ ] Transferência não autorizada é bloqueada pelo backend.
- [ ] Nota interna é persistida e exibida só a usuários autorizados; não é enviada ao WhatsApp.
- [ ] Durante atendimento humano **Em atendimento**, os fluxos não interferem na conversa (incluindo o módulo de fluxos já implementado).
- [ ] Histórico completo é obtido do provedor; prévias e conteúdo temporário respeitam limites, criptografia e limpeza da seção 3.
- [ ] A interface informa histórico indisponível ou recuperação pendente sem prometer acesso permanente ou completo a mensagens antigas; apresentação visual a definir no protótipo.
- [ ] Webhooks duplicados não produzem registros operacionais duplicados.
- [ ] Clientes web/mobile não recebem tokens privados dos provedores.
- [ ] Finalizar altera o status para **Finalizado**, libera o responsável ativo e preserva o último atendente, autor/horário da finalização, notas e eventos anteriores.
- [ ] Uma conversa finalizada deixa de corresponder ao filtro **Meus chats** por atribuição ativa.
- [ ] Finalização e criação de notas internas são autorizadas ao responsável e ao administrador; outros usuários são bloqueados.
- [ ] Mensagem recebida após finalização não abre atendimento humano sem gatilho definido para isso.
- [ ] Filtros usam dois seletores com as opções aprovadas e respeitam permissões.
- [ ] O painel de contato oferece nome, email, CPF e o histórico de notas internas, sem exigir campos adicionais.
- [ ] A primeira entrega oferece anexos documento, imagem e vídeo, gravação de mensagem de voz pelo microfone e um campo de busca na coluna das conversas.
- [x] Voz usa as mesmas permissões de resposta e isolamento de workspace; conversão temporária e envio simulado `ptt` são cobertos por testes locais sem conteúdo persistido.
- [x] Rejeição explícita é distinguida de confirmação pendente; timeout não reenvia e histórico concilia por `track_id`.
- [x] Bolinha de não lidas e leitura compartilhada pelo provedor; mensagem concorrente à confirmação preserva o contador local.
- [x] Caminho anterior: contador e versão protegem a leitura compartilhada sem histórico completo local. No modo de tempo real, prévias e payloads temporários seguem a seção 3; este registro não comprova homologação operacional do novo caminho.
- [x] Painel do chat ocupa a altura da tela; histórico rola sem mover cabeçalho ou compositor.
- [ ] Validar áudio real de ponta a ponta e navegadores/dispositivos antes do aceite operacional.
- [ ] Validar leitura e confirmação no WhatsApp usando a versão contratada da UAZAPI.

## 11. Decisões pendentes — não implementar sem validação

| ID   | Tema                              | Decisão ainda necessária                                                                                                                                                                                                                   |
| ---- | --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| P-01 | Finalização — atribuição decidida | **Aprovado:** liberar o responsável ao finalizar, preservando o último atendente e histórico. Ainda pendente: eventual preferência por ele em novo atendimento.                                                                            |
| P-02 | Entrada humana                    | **Implementado:** abertura manual, operação de fluxo e encaminhamento IA; mensagem sozinha não abre atendimento.                                                                                                                           |
| P-03 | Fluxos                            | **Definido e implementado:** prioridades, captura, interrupções e pausas no [Editor](editor-de-fluxos.md); Sequências têm contrato próprio.                                                                                                |
| P-04 | Distribuição                      | Atribuição automática/rodízio por fluxo, elegibilidade de atendentes.                                                                                                                                                                      |
| P-05 | Status                            | **Implementado:** abertura humana já bloqueia automações; finalização libera somente a pausa humana. Filtros atuais na seção 6.4.                                                                                                          |
| P-06 | Ações — atores decididos          | **Aprovado:** responsável ou administrador pode responder, finalizar e criar notas. Detalhes de mudança de responsável por ação administrativa seguem pendentes.                                                                           |
| P-07 | Notas                             | Criação pelo responsável ou administrador aprovada; definir edição, exclusão, anexos, menções e notificações.                                                                                                                              |
| P-08 | UAZAPI                            | Provedor e documentação oficial confirmados; validar contrato e operação da versão contratada conforme [registro de integração](../integracoes/uazapi.md).                                                                                 |
| P-09 | Detalhes da experiência do chat   | Dois seletores, busca na lista e anexos documento/imagem/vídeo aprovados em [Interface do Chat](../interfaces/chat.md); busca atual por nome/telefone e anexos conforme integração UAZAPI; homologação não é presumida pela implementação. |
| P-10 | Segurança                         | Política de retenção de metadados, auditoria, privacidade e tratamento de eventos.                                                                                                                                                         |
| P-11 | CRM                               | **Implementado:** validação do perfil na seção 6.4 e cadastros de etiquetas/campos de texto compartilhados com Fluxos. O briefing de Leads é distinto desses recursos existentes.                                                          |
| P-12 | Instalação mobile opcional        | Mesma aplicação web desktop/mobile confirmada; decidir posteriormente sobre instalação PWA e eventual distribuição em lojas.                                                                                                               |
| P-13 | Filtros de atendimento            | Decisão inicial preservada; comportamento observado atual e diferença em relação ao recorte original na seção 6.4.                                                                                                                         |

O [registro de decisões](../planejamento/decisoes.md) preserva o contexto das decisões. Esta tabela reúne estados históricos e referências atuais; não constitui um levantamento novo do que falta implementar.

## 12. Registro resumido de decisões

| ID   | Decisão                                                                                                                           | Estado                                                                            |
| ---- | --------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| D-01 | Laravel como base do SaaS e suporte a aplicativo móvel                                                                            | **Aprovada**                                                                      |
| D-02 | Workspace é tenant principal; sem entidade empresa superior                                                                       | **Aprovada**                                                                      |
| D-03 | Cobrança por workspace; um WhatsApp por workspace inicialmente                                                                    | **Aprovada**                                                                      |
| D-04 | Login global e vínculo a múltiplos workspaces                                                                                     | **Aprovada**                                                                      |
| D-05 | Contatos/CRM/atendimentos isolados por workspace                                                                                  | **Aprovada**                                                                      |
| D-06 | Histórico completo na UAZAPI, com prévia limitada e conteúdo temporário permitidos no modo de tempo real                          | **Revisão de 16/10/2026; seção 3 e decisão B-06**                                 |
| D-07 | Chat multiatendimento antes da implementação de fluxos                                                                            | **Aprovada**                                                                      |
| D-08 | Modelo original de permissões combináveis                                                                                         | **Aprovado no briefing; implementação observada descrita na seção 4**             |
| D-09 | Primeiro envio abre e assume; ação manual permanece no perfil e a atribuição é protegida contra corrida | **Atualizada em 16/10/2026** |
| D-10 | Distribuição automática/rodízio serão definidos no módulo de fluxos                                                               | **Adiada deliberadamente**                                                        |
| D-11 | Estado humano separado da automação; aberto/em atendimento/finalizado                                                             | **Aprovada em nível funcional**                                                   |
| D-12 | Enquanto em atendimento humano, fluxos/gatilhos ficam pausados                                                                    | **Aprovada**                                                                      |
| D-13 | Ao finalizar, liberar o responsável e preservar o último atendente/histórico                                                      | **Aprovada em 12/10/2026**                                                        |
| D-17 | Frontend Vue 3 + Inertia.js; Vue Flow no editor implementado                                                                      | **Aprovada em 12/10/2026**                                                        |
| D-18 | Nova mensagem segue processamento normal; atendimento humano só abre por gatilho que o determine                                  | **Aprovada em 12/10/2026**                                                        |
| D-14 | Permissão para transferir seus atendimentos delegada pelo admin                                                                   | **Briefing aprovado; código atual descrito na seção 5.2**                         |
| D-15 | Transferência imediata, sem aceite, e auditada                                                                                    | **Briefing aprovado; assumir como administrador é a operação atual da seção 5.2** |
| D-16 | Notas internas por conversa e workspace; criação por responsável ou administrador                                                 | **Aprovada**                                                                      |
| D-19 | Responsável ou administrador pode responder, finalizar e criar notas                                                              | **Aprovada em 12/10/2026**                                                        |
| D-20 | Dois seletores conforme aprovação inicial                                                                                         | **Decisão de 12/10/2026; diferenças da tela atual na seção 6.4**                  |
| D-21 | Contato inicial: nome, email e CPF; notas internas no perfil da conversa                                                          | **Revisada em 12/10/2026**                                                        |
| D-22 | Anexos documento, imagem e vídeo; busca na coluna de conversas                                                                    | **Aprovada em 12/10/2026**                                                        |
| D-23 | Indicador simples de não lidas e leitura compartilhada ao visualizar mensagens recentes; compositor fixo com rolagem no histórico | **Aprovada em 12/10/2026**                                                        |

## 13. Convenções de manutenção do documento

- Atualizar a tabela de decisões sempre que uma regra for aprovada, alterada ou adiada.
- Não transformar itens da seção **Proposta de arquitetura** em migrations/contratos definitivos sem uma decisão explícita.
- Ao implementar uma funcionalidade, acrescentar referências aos PRs, testes e endpoints reais.
- Preservar a distinção entre **regra de negócio**, **proposta técnica** e **ponto pendente**.
- Desenvolver e revisar cada módulo em etapas, evitando decisões antecipadas sobre fluxos.

### Histórico de versões

- **v0.6 — 2026-10-12:** confirmada mesma aplicação web para desktop/celular; frontend mobile independente removido da proposta.

- **v0.5 — 2026-10-12:** definida entrada humana apenas por gatilho, atores das ações, dois seletores, campos iniciais e anexos/busca; dependências restantes explicitadas.

- **v0.4 — 2026-10-12:** confirmada manutenção das mensagens somente na UAZAPI, aceitando histórico limitado; política de persistência e critérios de aceite atualizados.
- **v0.3 — 2026-10-12:** confirmados UAZAPI, um número por workspace, Vue 3 + Vue Flow e chat primeiro; aprovada liberação do responsável ao finalizar, preservando histórico; consolidada a cópia v0.2 e ajustados critérios de aceite.
- **v0.2 — 2026-10-12:** referência à especificação da interface do chat e esclarecimento de quais decisões de experiência já estão aprovadas.
- **v0.1 — 2026-10-12:** consolidação das decisões de descoberta de produto referentes a workspaces, chat multiatendimento, permissões, atribuições, transferências, estados humanos e notas internas.

- **v0.7 — 2026-10-12:** mensagem de voz aprovada, implementada e coberta por testes locais; critérios de aceite operacional de áudio registrados na integração e interface.
- **v0.8 — 2026-10-12:** leitura compartilhada com indicador, versão contra atualizações concorrentes e painel fixo do chat implementados localmente.

## Integração com Sequências — 14/10/2026

A abertura do atendimento humano, inclusive enquanto aguarda atendente, bloqueia a próxima etapa de Sequências. Abertura, atribuição/finalização e pausa usam a mesma ordem de travas do executor central. Interromper um fluxo da etapa por atendimento ou pausa encerra essa etapa com motivo; a seguinte só conta após todos os bloqueios terminarem. Pausas humana e manual são independentes.

O painel do contato mostra participação, etapa, prazo/saldo e impedimentos. Adicionar à sequência exige gestão de fluxos e acesso à conversa; usa o mesmo serviço da operação do editor. Escolher outra participação preserva o fluxo corrente. Regras completas em [Sequências](sequencias.md).
