# chAIt — Ordem de implementação para desenvolvimento

> Planejamento original de 12/10/2026, preservado como referência. As etapas e pendências abaixo não são um diagnóstico atual de entrega; consultar o [índice vigente](../README.md), os módulos e a [revisão de dados/tempo real](../operacao/chat-realtime.md). A proibição original de prévias foi revista em 16/10/2026.

> **Versão:** 1.0  
> **Data:** 12/10/2026  
> **Base:** revisão dos seis arquivos de briefing anexados.  
> **Destino sugerido no repositório:** `docs/planejamento/ordem-de-implementacao.md`  
> **Natureza:** planejamento técnico recomendado a partir das decisões documentadas. Este roteiro não transforma pendências de produto em decisões aprovadas nem comprova implementação.

## 1. Orientação principal

**Implementar primeiro o chat e multiatendimento completo, incluindo a experiência no celular. Depois entregar o editor visual e, por último, ativar o executor e os gatilhos.**

Antes de construir as telas definitivas, conferir o código existente, validar a UAZAPI contratada e estabelecer os contratos de autorização, estados e persistência. Essas dependências afetam diretamente o funcionamento do chat.

A primeira lacuna de produto é a entrada no atendimento humano: ela exige um gatilho/ação definido, mas o executor de fluxos só virá depois. Esse mecanismo precisa estar especificado antes de concluir o multiatendimento. Uma mensagem recebida não pode ser usada como abertura automática por conveniência de implementação.

O trabalho pode começar pela auditoria, pela validação do provedor e pela base de acesso enquanto essa lacuna é resolvida. Não é necessário fechar todas as decisões dos fluxos para entregar o chat.

## 2. Fontes consultadas

Os links desta seção seguem a organização canônica de `docs/` descrita no README; devem ser usados após colocar este roteiro nessa pasta.

| Arquivo recebido           | Documento canônico                                             | Versão/estado revisado   | Uso neste roteiro                                                       |
| -------------------------- | -------------------------------------------------------------- | ------------------------ | ----------------------------------------------------------------------- |
| `README.md`                | [README](../README.md)                                         | Atualizado em 12/10/2026 | Prioridade de entrega, stack e limites do escopo.                       |
| `decisoes.md`              | [Registro de decisões](decisoes.md)                            | B-01 a B-12              | Confirmações recentes e dependências compartilhadas.                    |
| `chat-multiatendimento.md` | [Chat e multiatendimento](../modulos/chat-multiatendimento.md) | v0.6                     | Workspace, permissões, atribuições, transferência, finalização e notas. |
| `chat.md`                  | [Interface do chat](../interfaces/chat.md)                     | v0.5                     | Desktop, celular, filtros, dados do contato e anexos.                   |
| `uazapi.md`                | [Integração UAZAPI](../integracoes/uazapi.md)                  | v0.2                     | Validações necessárias e política de histórico.                         |
| `editor-de-fluxos.md`      | [Editor de fluxos](../modulos/editor-de-fluxos.md)             | v0.3                     | Biblioteca, blocos, publicação, executor e gatilhos.                    |

Foi revisado o briefing disponibilizado. Código, infraestrutura e instância UAZAPI não foram inspecionados ou testados para produzir este roteiro. As capacidades do provedor mencionadas nos arquivos permanecem sujeitas à validação no ambiente contratado.

## 3. Principais decisões já documentadas

| Tema                     | Regra confirmada nas fontes                                                                                                                                   | Referência                                        |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| Stack                    | Laravel; frontend Vue 3 + Inertia.js; Vue Flow no editor. A base é descrita como React e precisa ser conferida/adaptada.                                      | B-03; README; interface §1; editor §4.1.          |
| Primeira entrega         | Chat e multiatendimento precedem editor e automações.                                                                                                         | B-04; README §Ordem de entrega.                   |
| Workspace                | Tenant principal, sem entidade empresa acima dele; um WhatsApp por workspace inicialmente.                                                                    | B-02; multiatendimento §§2–2.1.                   |
| Usuários                 | Login global, com vínculos e permissões independentes em cada workspace.                                                                                      | Multiatendimento §2.                              |
| Isolamento               | Contatos, conversas, notas e atendimentos separados por workspace. O mesmo telefone pode ter cadastros separados em workspaces diferentes.                    | Multiatendimento §2.1.                            |
| WhatsApp                 | UAZAPI como provedor inicial; acesso intermediado pelo Laravel e credenciais protegidas.                                                                      | B-01; integração §Direção aprovada.               |
| Histórico de mensagens   | Conteúdo/histórico fica somente na UAZAPI; retenção limitada aceita. Dados operacionais, contatos e notas internas permanecem no SaaS.                        | B-06; multiatendimento §3; integração §Histórico. |
| Visibilidade             | Permissões combináveis para todas, próprias e não atribuídas. Poder visualizar não concede automaticamente ações.                                             | Multiatendimento §4.                              |
| Assumir                  | Atribuição manual e atômica; atendente comum precisa ser responsável para responder.                                                                          | Multiatendimento §§4.1–5.1.                       |
| Transferir               | Transferência imediata, sem aceite, entre membros autorizados do mesmo workspace; permissão para transferir os próprios atendimentos.                         | Multiatendimento §5.2.                            |
| Ações humanas            | Responsável ou administrador pode responder, finalizar e criar notas, respeitando o contexto de atendimento humano.                                           | B-08; multiatendimento §4.1.                      |
| Finalização              | Liberar responsável ativo; preservar último atendente, notas e eventos; registrar autor e horário.                                                            | B-05; multiatendimento §6.2.                      |
| Entrada humana           | Só ocorre por gatilho/ação definido. Mensagem recebida, inclusive após finalizar, não abre atendimento humano por si só.                                      | B-07; multiatendimento §6.3.                      |
| Robô durante atendimento | Enquanto **Em atendimento**, automações e palavras-chave não devem interferir. Pausa em **Aberto** e retomada ainda aparecem como pendentes nestas fontes.    | Multiatendimento §6.1; editor §10.1.              |
| Interface                | Mesma aplicação web para desktop e celular. Desktop com quatro áreas; celular com uma tela por vez e menu em drawer.                                          | B-12; interface UI-01 a UI-07.                    |
| Filtros                  | Dois seletores: Todos / Meus chats / Não atribuídos; Todos / Abertos / Concluídos. Substituem as antigas abas.                                                | B-09; interface UI-04.                            |
| Contato e anexos         | Nome, email e CPF no perfil, com notas internas da conversa; busca na lista; anexos documento, imagem e vídeo no chat inicial.                                | B-11 e B-23.                                      |
| Editor                   | Biblioteca com pastas/subpastas; Start único; cinco tipos de bloco; painel à esquerda e criação de bloco conectado pelo `+` da saída.                         | Editor §§2–5.                                     |
| Gatilhos                 | Configurados por conexão. Fluxos normais reutilizados como boas-vindas, resposta padrão ou destino de palavra-chave.                                          | Editor §§3.2 e 6.                                 |
| Palavras-chave           | É igual a / Começa com / Contém; sem distinção de maiúsculas ou acentos; ordem visual decide prioridade; interrupção independente e desligada por padrão.     | Editor §6.3.                                      |
| Boas-vindas              | Primeiro recebimento por contato/conexão, no máximo uma vez; palavra-chave elegível tem prioridade.                                                           | Editor §6.1.                                      |
| Resposta padrão          | Contato conhecido, sem palavra-chave elegível, sem execução ativa e sem pausa.                                                                                | Editor §6.2.                                      |
| Execução                 | No backend, independente de navegador aberto; esperas com disputa atômica entre resposta e timeout; jobs antigos não podem continuar execuções interrompidas. | Editor §§5.3 e 7.                                 |

Detalhes comerciais de assinatura/cobrança, CRM completo, canais adicionais, PWA e distribuição em lojas não integram automaticamente a primeira entrega.

## 4. Ajustes necessários na documentação

Não reabrir decisões já confirmadas por causa de um trecho antigo. Usar as confirmações explícitas e atualizar os documentos afetados no repositório.

| Local                                                  | Problema encontrado                                                                                                    | Tratamento recomendado                                                                                                                                    |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Multiatendimento §6.2, parágrafo “Continuam pendentes” | Ainda lista quem pode finalizar e o comportamento de nova mensagem como indefinidos.                                   | Sincronizar com B-07, B-08, §§4.1 e 6.3 e critérios de aceite. Responsável/admin já estão autorizados; nova mensagem não abre atendimento humano sozinha. |
| Multiatendimento §6.1                                  | O título de pendências também contém regras explicitamente aprovadas.                                                  | Separar visualmente confirmações de pendências reais.                                                                                                     |
| Multiatendimento P-03                                  | Lista gatilhos e prioridades de forma genérica como pendentes, embora parte esteja detalhada/aprovada no editor.       | Referenciar o editor §6; manter pendentes apenas comportamentos ainda abertos, como pausa/retomada.                                                       |
| Editor §10.2                                           | Publicação versionada aparece entre recomendações, embora §§4.3 e 7 e AC25 exijam estabilidade da definição publicada. | Preservar o comportamento exigido: editar rascunho não altera execução em produção. O formato técnico de armazenamento pode ser escolhido pelo dev.       |
| Editor §§6.2/6.4 versus §10.1, item 4                  | O caminho sem boas-vindas configurado no primeiro contato não está inteiramente harmonizado.                           | Fechar essa regra antes de ativar gatilhos; não inferir resposta padrão para esse caso.                                                                   |

Os anexos originais não foram alterados por este roteiro. A harmonização acima deve fazer parte da preparação no repositório.

## 5. Sequência resumida

“Depende de” indica a condição para concluir a etapa; protótipos e preparações podem avançar antes, desde que não introduzam regras ainda abertas.

| Etapa | Implementação                                     | Depende de                                           | Entrega verificável                                                 |
| ----- | ------------------------------------------------- | ---------------------------------------------------- | ------------------------------------------------------------------- |
| 00    | Auditar o projeto e harmonizar o briefing         | Código e infraestrutura disponíveis ao dev           | Mapa do que existe, do que será reutilizado e do que falta.         |
| 01    | Validar UAZAPI e definir contratos do chat        | Ambiente de teste e decisões críticas do chat        | Prova técnica da integração e contratos documentados.               |
| 02    | Base Vue/Inertia, login, workspaces e autorização | Auditoria e modelagem mínima                         | Aplicação com troca segura de workspace e permissões.               |
| 03    | Integração de mensagens e eventos                 | Etapas 01 e 02                                       | Conversas/histórico pelo provedor e eventos sem duplicação.         |
| 04    | Backend do multiatendimento                       | Base isolada e mecanismo de abertura humana definido | Assumir, transferir, finalizar e notas com autorização e auditoria. |
| 05    | Chat completo no desktop e celular                | Contratos e serviços das etapas 03 e 04              | Interface integrada, contatos, filtros, busca e anexos.             |
| 06    | Homologar e liberar o chat                        | Etapas 02–05                                         | **Marco 1: chat e multiatendimento utilizáveis.**                   |
| 07    | Protótipo Vue Flow e contratos dos fluxos         | Marco 1; pendências do executor mapeadas             | Editor visual validável sem executar automações.                    |
| 08    | Biblioteca, blocos, dados de apoio e publicação   | Etapa 07 e contratos dos campos/etiquetas            | **Marco 2: criar, salvar e publicar fluxos válidos.**               |
| 09    | Executor no Laravel                               | Publicação e decisões críticas da execução           | Nós, envios, operações, esperas e cancelamento verificados.         |
| 10    | Gatilhos e integração com atendimento humano      | Executor e política de pausa/retomada                | Entrada por palavras-chave, boas-vindas e resposta padrão.          |
| 11    | Homologar e ativar automações                     | Etapas 08–10                                         | **Marco 3: automações operacionais integradas ao chat.**            |

## 6. Etapas de implementação

### Etapa 00 — Auditar a base e preparar o trabalho

**Objetivo:** adaptar o plano ao projeto real e evitar duplicar entidades ou reescrever partes úteis.

**Trabalho do dev:**

- Conferir versões reais de Laravel, PHP, frontend, banco, autenticação, Redis/Horizon e processo de deploy. Não assumir banco ou infraestrutura de outros projetos.
- Mapear o frontend descrito como React e definir a adaptação para Vue 3 + Inertia sem perder autenticação e funcionalidades existentes.
- Identificar entidades atuais de usuário, workspace, contato, conversa, permissões e arquivos. Reutilizá-las quando forem compatíveis.
- Colocar as especificações e este roteiro na estrutura canônica de `docs/`; harmonizar os trechos da seção 4.
- Preparar ambiente de desenvolvimento/homologação e separar credenciais por ambiente.

**Aceite:** existe um inventário curto com o que está implementado, o que precisa mudar e as dependências externas. A estimativa de prazo deve partir desse inventário, não apenas do briefing.

### Etapa 01 — Provar a integração e fechar os contratos do chat

**Objetivo:** descobrir limitações do provedor antes de construir uma interface que dependa de recursos não confirmados.

**Trabalho do dev:**

- Validar na versão contratada: autenticação, instância, conexão/pareamento, reconexão, listagem de chats, histórico, paginação, identificadores e webhooks.
- Testar recebimento/envio de texto e dos anexos aprovados: documento, imagem e vídeo. Confirmar limites, acesso e expiração das mídias.
- Conferir eventos duplicados, atrasados, mensagens enviadas pelo sistema e sincronização de histórico. Identificar quais eventos realmente representam nova mensagem do cliente.
- Definir o contrato do adaptador de canal e os metadados locais necessários; guardar resultados técnicos sem tokens ou payloads contendo mensagens reais.
- Documentar máquina de estados, mecanismo inicial de abertura humana, regras dos filtros, campos da busca e contrato das notas/edição de contato.
- Escolher atualização do chat e notificação de transferência conforme o ambiente real; registrar o comportamento de indisponibilidade.

**Aceite:** o dev consegue demonstrar consulta e envio em homologação e explicar como o chat operará sem salvar histórico de mensagens no Laravel. O contrato da primeira abertura humana está resolvido antes da conclusão da etapa 04.

**Observação:** provedor aprovado não significa todas as capacidades verificadas. Uma limitação encontrada deve ajustar o contrato ou ser encaminhada como decisão de produto, sem criar promessa na interface.

### Etapa 02 — Construir a base de acesso e isolamento

**Objetivo:** ter a estrutura segura na qual todo o chat será apoiado.

**Trabalho do dev:**

- Adaptar a aplicação web para Vue 3 + Inertia e criar o layout compartilhado com navegação responsiva.
- Implementar/reutilizar login global, vínculos a workspaces e seleção apenas de workspaces autorizados.
- Implementar permissões por vínculo e Policies/Gates no backend; separar leitura das ações de atendimento.
- Modelar conexão separada do workspace, com limite inicial de um WhatsApp e credenciais protegidas.
- Definir invariantes e índices/constraints antes das migrations. Nomes de tabelas sugeridos no briefing não constituem esquema definitivo.
- Aplicar escopo de workspace também a cache, eventos/broadcasts, notificações e tarefas em fila.

**Aceite:** um usuário pode ter permissões diferentes em dois workspaces; trocar IDs na requisição não permite acessar contatos, notas, conversas ou conexão de outro workspace. Tokens privados não chegam ao frontend.

### Etapa 03 — Integrar conversas, histórico e eventos

**Objetivo:** disponibilizar mensagens por meio da UAZAPI e manter no SaaS os dados operacionais necessários.

**Trabalho do dev:**

- Implementar o adaptador UAZAPI, sem espalhar detalhes do provedor pelos controllers e regras de atendimento.
- Receber webhooks conforme autenticação validada; derivar o workspace da conexão confiável no backend.
- Identificar/criar contatos e referências de conversa dentro do workspace, sem abrir atendimento humano automaticamente.
- Persistir somente os metadados necessários à operação e deduplicação. Montar a lista combinando dados locais autorizados e dados disponíveis no provedor.
- Carregar histórico paginado pelo backend e tratar indisponibilidade, recuperação incompleta e mídia expirada.
- Preparar envio com correlação e estado de resultado; após timeout, evitar repetição cega de um envio possivelmente realizado.
- Registrar metadado do primeiro recebimento conhecido por contato/conexão desde o chat, preparando a futura regra de boas-vindas. Definir o tratamento de bases anteriores antes de ativar esse gatilho.

**Aceite:** webhooks repetidos não criam contatos/eventos duplicados; histórico vem do provedor; mensagem recebida não abre sessão humana; reconexão e sincronização não são interpretadas indiscriminadamente como novas entradas.

**Cuidados de paginação:** aplicar autorização e filtros operacionais antes de devolver itens e contagens. Filtrar apenas uma página do provedor depois da consulta pode gerar páginas vazias e totais incorretos; a composição precisa ser verificada com várias páginas.

### Etapa 04 — Implementar o domínio do multiatendimento

**Objetivo:** concluir as regras de atendimento com serviços reutilizáveis pelo futuro executor.

**Trabalho do dev:**

- Criar uma ação centralizada de abrir atendimento humano, usada pelo mecanismo inicial definido e posteriormente pelos fluxos.
- Implementar **Assumir**, **Transferir**, **Finalizar** e **Criar nota**, aplicando as permissões documentadas.
- Tornar a atribuição atômica; usar atualização condicional ou bloqueio/transação compatível com o banco real. Conflitos de estado devem atualizar a interface.
- Transferir imediatamente apenas para membro elegível do mesmo workspace; auditar origem/destino e notificar o novo responsável pelo mecanismo definido.
- Finalizar liberando o responsável ativo e preservando último atendente, notas e autor/horário dos eventos.
- Separar nota interna de mensagem WhatsApp. Aplicar às notas a mesma visibilidade da conversa.
- Revalidar autorização e estado vigente nas ações; a tela aberta antes de uma transferência não mantém permissão de envio.

**Aceite:** dois atendentes disputam uma conversa e apenas um assume; quem perde a atribuição não continua respondendo; administrador e responsável executam as ações aprovadas; outros são bloqueados; nota interna nunca é enviada ao WhatsApp.

**Contrato de estado:** não usar “sem responsável” como sinônimo de “aguardando atendimento”. Uma conversa finalizada também fica sem responsável. A ausência de sessão humana não é automaticamente um atendimento **Aberto**. Resolver essas distinções no modelo e nos filtros.

**Limite desta etapa:** preparar a separação entre estado humano e automação; a prova de que o robô fica bloqueado será realizada quando existir o executor.

### Etapa 05 — Entregar a interface completa do chat

**Objetivo:** tornar o atendimento utilizável no desktop e no celular na mesma aplicação web.

**Trabalho do dev:**

- Desktop com quatro áreas: navegação expansível, lista, conversa ativa e painel do contato.
- Celular com lista → conversa em tela cheia → contato pelo cabeçalho; menu em drawer.
- Implementar os dois seletores combináveis e busca nos campos definidos. **Todos** nunca amplia autorização.
- Integrar assumir, transferir, finalizar, notas e atualização de responsável/visibilidade.
- Oferecer edição de nome, email e CPF conforme contrato de validação e autorização; exibir notas internas da conversa no perfil.
- Implementar texto e anexos documento/imagem/vídeo, com erros e limites confirmados no provedor.
- Mesclar notas internas e mensagens disponíveis numa apresentação que as diferencie claramente.
- Exibir carregamento, falha, desconexão e histórico indisponível; preservar contexto ao navegar no celular.
- Invalidar dados em memória e assinaturas de eventos ao mudar de workspace ou perder acesso à conversa.

**Aceite:** o mesmo usuário consegue atender pelo computador e pelo celular, dentro de suas permissões. As ações modificam o backend, os filtros refletem o estado atual e a interface não promete histórico permanente.

PWA, funcionamento offline, gravação de áudio e frontend mobile separado não são requisitos desta entrega.

### Etapa 06 — Homologar e liberar o primeiro marco

**Objetivo:** entregar chat completo antes de avançar para automações.

**Verificação mínima:**

- Dois workspaces com contatos de mesmo telefone e usuários com permissões distintas.
- Permissões de todas/próprias/não atribuídas e tentativa de acesso direto por ID.
- Disputa para assumir, transferência concorrente com ação antiga e finalização com liberação de responsável.
- Nova mensagem depois de finalizar sem reabertura humana automática.
- Notas internas, contatos, filtros, busca e anexos conforme os contratos fechados.
- Webhooks duplicados, reconexão, falha/timeout de envio, mídia expirada e histórico incompleto.
- Desktop e celular, incluindo troca de workspace e perda de acesso.
- Inspeção de banco, filas, tarefas falhas, logs e monitoramento para verificar a política de não persistir mensagens.

**Entrega:** ambiente homologado, instruções operacionais básicas e evidências de aceite referenciadas nos documentos/PRs. Este é o **Marco 1**; não depende de editor ou CRM completo.

### Etapa 07 — Prototipar o editor e definir os contratos dos fluxos

**Objetivo:** validar o editor sem ativar automações enquanto as regras de execução são fechadas.

**Trabalho do dev:**

- Criar página Vue Flow com um único Start, canvas livre, painel à esquerda e menu global de blocos.
- Implementar o `+` da saída para adicionar Conteúdo já conectado; salvar/reabrir posições e conexões do protótipo.
- Definir um esquema compartilhado frontend/backend para nós, saídas e configurações com IDs estáveis.
- Fechar os contratos pendentes que afetam execução: pausa/retomada, abertura humana, captura de resposta, término de ramo, falhas e teste seguro.
- Definir permissões de criar, editar, publicar e configurar gatilhos. Não inferir que qualquer usuário do chat terá acesso ao editor.

**Aceite:** protótipo demonstrável, estrutura de blocos documentada e pendências de execução encaminhadas antes da ativação do motor. Criar o canvas não equivale a executar um fluxo.

### Etapa 08 — Biblioteca, dados de apoio e publicação

**Objetivo:** permitir montar e publicar definições consistentes para o executor consumir.

**Trabalho do dev:**

- Implementar/reutilizar etiquetas e campos personalizados de texto: definição no workspace e valor por contato. Não criar um CRM completo nessa etapa.
- Criar biblioteca com pastas/subpastas, fluxos na raiz, busca e renomeação; mover pasta não altera IDs ou referências.
- Implementar os cinco blocos aprovados: Conteúdo, Aguardar Resposta, Operações, Ir para Fluxo e Condição, além do Start único.
- Implementar configuração e validação backend dos conectores: Próximo passo; Respondeu/Não respondeu; Sim/Não; Ir para Fluxo sem saída.
- Permitir rascunho incompleto e publicar somente definições válidas. Preservar versões publicadas usadas pelas execuções.
- Validar referências no workspace, destino publicado de Ir para Fluxo e ciclos entre fluxos.
- Definir acesso às mídias usadas nas definições e tratamento de exclusão/desativação de fluxos referenciados.

**Aceite:** salvar/reabrir mantém grafo e posições; validação informa o bloco com erro; rascunho não altera a versão em produção; etiquetas e campos não se misturam entre contatos/workspaces. Este é o **Marco 2**.

**Persistência:** textos/mídias configurados pelo administrador no fluxo são dados de configuração; não representam uma autorização para copiar histórico de mensagens recebidas. A captura de resposta em campo do contato exige sua fronteira específica.

### Etapa 09 — Construir o executor incrementalmente

**Objetivo:** executar a definição publicada no Laravel, sem depender de uma aba aberta.

**Ordem interna recomendada ao dev:**

1. Estado de execução, seleção de versão publicada, transições, limites e verificação de jobs obsoletos.
2. Start e Conteúdo: itens em ordem, envio pelo adaptador e atrasos sem manter worker bloqueado em espera.
3. Operações sobre etiquetas/campos e Condição com Todas (E) / Pelo menos uma (OU).
4. Ir para Fluxo: encerrar origem, iniciar destino no Start e impedir retorno/ciclos.
5. Aguardar Resposta: persistir a espera, disputar resposta/timeout atomicamente e impedir preenchimento de outro contato.
6. Operações de atendimento humano, notificações e pausa, reutilizando o domínio do chat e a política definida.

**Garantias a construir junto com cada ação:** idempotência, workspace correto, execução ainda ativa, versão/passo esperado, controle de pausa e observabilidade sem mensagens recebidas nos logs.

**Aceite:** envios respeitam ordem; uma espera segue apenas Respondeu ou Não respondeu; resposta tardia não reabre espera; campo pertence ao contato certo; execução interrompida não volta por job antigo; falha do provedor não é registrada como sucesso.

Execução pausada ou atendimento humano bloqueante deve ser rechecado no momento do envio, inclusive em tarefas agendadas antes da pausa. A política de retomada precisa estar definida; não liberar automaticamente uma pausa manual ao finalizar atendimento.

### Etapa 10 — Gatilhos e integração entre robô e equipe

**Objetivo:** transformar mensagens novas do cliente em entradas previsíveis, sem competir com o atendimento humano.

**Trabalho do dev:**

- Configurar por conexão os fluxos publicados de boas-vindas, resposta padrão e palavras-chave.
- Implementar os três operadores com a normalização aprovada; ordem visual é a prioridade entre regras elegíveis, sem heurística adicional.
- Sem execução ativa: procurar primeira palavra-chave elegível; depois primeiro contato/boas-vindas; para contato conhecido sem fluxo ativo, resposta padrão.
- Com execução ativa: somente palavra-chave com interrupção ligada pode iniciar novo fluxo; a varredura pode continuar após regra correspondente sem permissão de interromper.
- Com Aguardar Resposta: pausa tem precedência; depois palavra-chave interruptiva; depois captura da resposta ainda válida. A mesma mensagem não pode preencher a pergunta antiga e disparar outro fluxo.
- Interromper atomicamente a execução anterior e invalidar esperas/continuações antigas.
- Preservar o primeiro contato por contato/conexão; transferência e finalização não resetam esse registro. Se palavra-chave vencer no primeiro contato, não compensar com boas-vindas depois.
- Reutilizar a ação de abertura humana; aplicar o bloqueio do robô e a retomada conforme o contrato fechado.
- Excluir mensagens próprias e sincronizações de histórico do processamento de gatilhos de novas mensagens do cliente.

**Aceite:** uma mensagem não inicia dois fluxos conflitantes; durante Em atendimento não há resposta do robô; gatilhos respeitam pausa e prioridade; nova mensagem só abre atendimento humano se alcançar ação definida para isso.

### Etapa 11 — Homologar e ativar as automações

**Objetivo:** validar o conjunto editor → versão publicada → execução → gatilho → atendimento humano.

**Verificação mínima:**

- Cenários AC01–AC25 do editor, incluindo palavras-chave concorrentes, interrupção, primeiro contato, resposta padrão, rascunho e mudança de pasta.
- Disputa de resposta/timeout; mensagens simultâneas do mesmo contato; webhook repetido e fora de ordem; jobs antigos após interrupção.
- Pausa manual e humana, atendimento ainda não assumido, finalização e retomada conforme política definida.
- Falha/timeout da UAZAPI em envio, mídias e notificação, sem repetição cega de mensagem.
- Segurança de etiquetas, campos, referências de fluxo e membros entre workspaces.
- Reinício de workers durante atraso/espera e continuidade apenas de execuções válidas.
- Ativação com contatos já existentes: verificar que a implantação não transforma todos em primeiro contato.

**Entrega:** **Marco 3**, com monitoramento de falhas/execuções e procedimento para desativar automações preservando o chat. Registrar evidências de testes, versão e implantação.

## 7. Pendências e momento em que bloqueiam

As recomendações abaixo são propostas deste roteiro, não aprovações adicionais. O dev pode escolher detalhes de engenharia e registrá-los; mudanças de comportamento do produto exigem alinhamento antes da funcionalidade afetada.

| Pendência                                          | Quando precisa estar resolvida                        | Encaminhamento recomendado                                                                                                                                                                  |
| -------------------------------------------------- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Abertura humana sem executor                       | Antes de concluir a etapa 04                          | Definir um gatilho/ação inicial explícito e reutilizável pelo futuro bloco Operações. A forma de acionamento continua aberta; não equiparar recebimento ou visualização a abertura.         |
| Estados e filtros                                  | Antes das migrations do atendimento e da etapa 05     | Documentar as combinações de atribuição/status. Avaliar Abertos = Aberto + Em atendimento; distinguir finalizado sem responsável de fila aguardando.                                        |
| Busca e dados do contato                           | Antes da etapa 05                                     | Definir validação/obrigatoriedade de nome/email/CPF e autorização de edição; a busca inicial usa nome e telefone, e somente notas internas aparecem no perfil.                              |
| Edição/exclusão de notas                           | Antes de oferecer essas ações                         | Implementar criação/leitura aprovadas; acrescentar edição/exclusão somente com regra e auditoria definidas. Não bloqueia criação/leitura.                                                   |
| Atualização e notificação de transferência         | Antes do aceite do chat                               | Escolher mecanismo compatível com a infraestrutura; verificar autorização também no canal de atualização.                                                                                   |
| Política de pausa e retomada                       | Antes das operações humanas do executor e da etapa 10 | Definir comportamento em Aberto/Em atendimento/Finalizado, origem da pausa, destino da execução anterior e efeito em esperas. Separar pausa manual da pausa humana.                         |
| Resposta em campo personalizado                    | Antes de implementar captura real na etapa 09         | Formalizar o dado estruturado permitido, tipos de mensagem aceitos, sobrescrita e comportamento durante pausa. A aprovação de campo texto não aprova guardar mensagens indiscriminadamente. |
| Término, falhas e limites do fluxo                 | Antes de concluir executor/publicação afetados        | Definir saídas sem ligação, limites de atraso/timeout, falha em operação/mídia e tratamento de remoção/desativação de fluxo.                                                                |
| Permissões e teste do editor                       | Antes de publicar/ativar fluxos                       | Definir atores de criação/publicação/gatilhos e como testar sem disparo real acidental.                                                                                                     |
| Primeiro contato sem boas-vindas; bases anteriores | Antes de ativar gatilhos                              | Harmonizar o primeiro caso no briefing e estabelecer o marco de implantação/importação. Ausência de histórico disponível não prova primeiro contato.                                        |

## 8. Regras de engenharia transversais

### 8.1. Persistência e filas

- **Pode persistir conforme o briefing:** contatos, vínculos, permissões, referências de conversa, estados, responsáveis, notas internas e eventos operacionais. Na fase de fluxos: definições, versões, regras, estados e metadados de execução.
- **Não copiar como histórico:** texto/corpo de mensagens WhatsApp em tabelas, payloads de filas duráveis, tarefas falhas, logs ou monitoramento. Preview de mensagem também é conteúdo; não criar persistência dessa prévia por conveniência.
- Enfileirar referências/metadados e consultar o provedor quando necessário é uma estratégia a validar. Se a API não permitir recuperar a informação necessária, resolver o contrato antes de ativar o processamento; não presumir que reconsulta ou armazenamento temporário durável sejam automaticamente permitidos.
- Definir acesso e descarte de arquivos temporários usados em upload, sem transformá-los em arquivo permanente de mensagens recebidas. Não prometer cache offline de histórico no celular.
- Captura em campo personalizado deve obedecer ao contrato específico fechado para o Aguardar Resposta.

### 8.2. Concorrência e envio

- Centralizar as ações de domínio: abrir, assumir, transferir, finalizar, registrar nota e bloquear/desbloquear automações conforme a política.
- Usar transações/atualizações condicionais para mudanças locais e restrições de unicidade para correlação/idempotência; evitar transações longas aguardando rede.
- Revalidar estado e autorização de tarefas atrasadas e de ações iniciadas antes de transferência/finalização.
- Deduplicar webhook não garante que uma chamada externa terá entrega exatamente uma vez. Após timeout, usar a correlação/consulta disponível e informar resultado incerto quando não for possível confirmá-lo.
- Registrar apenas os metadados necessários ao diagnóstico: workspace, conexão, contato, evento, ação, fluxo/versão/bloco quando houver, resultado e horários.

### 8.3. Testes e acompanhamento

- Priorizar testes de integração e concorrência para isolamento, permissões, atribuição, envio incerto, captura e timeout; verificar navegação desktop/mobile nos marcos de entrega.
- Para cada etapa, criar tarefa/PR com fonte da regra, dependências, implementação e evidência do aceite.
- Atualizar a documentação junto com mudanças de comportamento; marcar implementado somente quando houver código e validação correspondentes.
- Propostas de schema, biblioteca ou mecanismo de atualização devem ser registradas como escolhas técnicas, sem criar novo fluxo de aprovação para cada detalhe de engenharia.

## 9. Primeiro trabalho a repassar ao dev

**Executar a etapa 00: auditar o projeto real e devolver o mapa do que já existe, do que será reaproveitado e das adaptações necessárias para Vue/Inertia, workspaces e UAZAPI.**

Depois desse inventário, seguir a ordem deste roteiro e usar os três marcos de entrega para acompanhar o progresso: chat utilizável; editor/publicação; automações integradas.
