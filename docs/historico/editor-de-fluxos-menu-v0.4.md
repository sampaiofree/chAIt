> **Versão alternativa preservada:** briefing v0.4 com especificação do Menu. Consulte o [documento principal do editor](../modulos/editor-de-fluxos.md) para o estado da implementação; os conteúdos diferentes desta cópia foram mantidos para consulta.

# Editor Visual de Fluxos e Automações

> **Especificação funcional e técnica — v0.4**  
> **Projeto:** SaaS Laravel de atendimento e automações multicanal  
> **Data:** 14/10/2026  
> **Estado:** especificação de etapa posterior ao chat; decisões funcionais consolidadas e pendências explícitas  
> **Destino no repositório:** `docs/modulos/editor-de-fluxos.md`

## 1. Objetivo

Permitir que um administrador monte automações de atendimento de maneira visual, arrastando blocos no canvas, conectando-os com setas e configurando as ações sem programar.

O foco da versão inicial é **automação de conversas recebidas pelo WhatsApp**, aproveitando os contatos, as etiquetas, os campos personalizados e o módulo de atendimento humano já existentes ou previstos no SaaS.

Esta especificação **não substitui** a documentação do módulo de multiatendimento (`docs/modulos/chat-multiatendimento.md`): os dois módulos devem compartilhar os mesmos conceitos de workspace, contato, conversa, conexão WhatsApp, membro da equipe e permissões.

**Direção aprovada em 12/10/2026:** UAZAPI como provedor inicial, um número de WhatsApp por workspace e frontend Vue 3 + Inertia.js com Vue Flow neste editor. **Chat e multiatendimento são a primeira entrega.** Este documento especifica uma etapa posterior, sem antecipar sua implementação.

### 1.1. Princípios aprovados

- Interface simples para usuários não técnicos.
- Poucos tipos de bloco, cada um com responsabilidade bem definida.
- Canvas limpo, com conexões explícitas e sem ramificações ocultas.
- Reutilização de fluxos e de campos personalizados do contato.
- Admin controla as prioridades e regras de palavras-chave.
- Execução real no backend; o editor visual **não executa** automações.
- Entrega incremental, sem incluir funções futuras na primeira versão.

## 2. Escopo funcional da v0.1

### 2.1. Aprovado

1. Biblioteca de fluxos com pastas e subpastas, permitindo fluxos também fora de pastas.
2. Editor visual com bloco **Start** inicial, canvas livre e painel de edição à esquerda.
3. Seis tipos de blocos funcionais: **Conteúdo**, **Aguardar Resposta**, **Operações**, **Ir para Fluxo**, **Condição** e **Menu**. O bloco Menu foi aprovado em 14/10/2026; a integração com a UAZAPI depende de homologação operacional.
4. Conectores visíveis, fixos por tipo, com saídas adicionais quando houver bifurcação.
5. Botão `+` no canto superior direito para adicionar bloco.
6. Botão `+` próximo à saída dos blocos para criar automaticamente o próximo bloco **já conectado**.
7. Fluxos comuns podem ser selecionados como **Boas-vindas**, **Resposta padrão** ou destino de **Palavras-chave**.
8. Configuração dos gatilhos no contexto da conexão de WhatsApp.
9. Palavras-chave com operadores `É igual a`, `Começa com` ou `Contém`, prioridade pela ordem visual e opção de interromper fluxo ativo.
10. Fluxo de boas-vindas executado apenas no primeiro contato de cada lead com cada conexão WhatsApp, se uma palavra-chave não for acionada primeiro.
11. Fluxo de resposta padrão quando não há palavra-chave aplicável, não é primeiro contato e não há fluxo ativo.
12. Automações pausadas para o atendimento humano não devem ser acionadas por gatilhos de palavra-chave ou de resposta padrão.

### 2.2. Fora do escopo desta versão

- Outros canais além do WhatsApp para disparo automático.
- Gatilhos por evento de compra, webhook externo, horário, campanha, API pública ou agendamento.
- Motor de condições complexo com grupos aninhados `E/OU`.
- Variáveis com validação avançada (CPF, e-mail, datas etc.).
- IA, reconhecimento semântico de intenção ou correspondência aproximada de palavras-chave.
- Versões diferentes do fluxo em testes A/B ou analytics avançado.
- Marketplace de modelos e importação/exportação de automações.

## 3. Biblioteca de fluxos

### 3.1. Organização

A tela anterior ao editor deve permitir:

- Criar, renomear e organizar **pastas**.
- Criar **subpastas**, sem limitar a organização a apenas um nível na experiência do usuário.
- Criar um fluxo dentro de uma pasta ou na raiz, sem pasta.
- Nomear o fluxo durante a criação e renomeá-lo posteriormente.
- Encontrar fluxos pelo nome e navegar pela estrutura de pastas.
- Diferenciar fluxos em **rascunho** dos **publicados**.

**Regra importante:** mover um fluxo entre pastas **não** muda seu identificador nem quebra referências por palavra-chave ou por bloco `Ir para Fluxo`. Referências internas nunca devem depender de caminho ou nome de pasta.

### 3.2. Natureza dos fluxos

Todos os fluxos são da mesma espécie. **Boas-vindas** e **Resposta padrão** não são tipos especiais de fluxo: são apenas fluxos normais selecionados em configurações.

Um mesmo fluxo pode ser:

- Marcado como boas-vindas de determinada conexão;
- Associado a uma ou mais palavras-chave;
- Escolhido como resposta padrão;
- Destino de um bloco `Ir para Fluxo`.

A interface não deve impedir esses usos simultâneos.

## 4. Editor visual (frontend)

### 4.1. Tecnologias acordadas

A base atual usa React; a escolha aprovada é Vue 3. A adaptação da base é trabalho futuro e não foi realizada durante o briefing.

| Camada | Tecnologia | Responsabilidade |
|---|---|---|
| Editor | Vue 3 + **Vue Flow** | Nós, conectores, setas, zoom, pan e drag-and-drop |
| Aplicação web | **Inertia.js** | Integração frontend/backend |
| Interface e estilos | Padrões já adotados no projeto | Componentes, estados e consistência visual |
| Backend | **Laravel** | Validação, publicação, gatilhos e execução de automações |
| Filas | **Redis + Horizon** | Execução assíncrona e espera agendada |
| Persistência | Banco de dados já adotado no SaaS | Fluxos, versões, regras e estado de execução |

> Não pressupor MySQL ou PostgreSQL sem conferir a infraestrutura real do novo projeto.

### 4.2. Composição da tela

- **Barra superior discreta:** nome do fluxo, status e ações `Salvar`, `Testar` e `Publicar` (a experiência exata de teste fica pendente).
- **Canvas central:** área clara e livre, com zoom, deslocamento e arraste de blocos.
- **Painel de edição à esquerda:** abre ao selecionar ou criar bloco; fecha quando não há bloco selecionado.
- **Botão global `+`:** canto superior direito, abre menu com os tipos de bloco.
- **Botão `+` nas saídas:** adiciona novo bloco no canvas, já ligado à saída que originou a ação.
- **Blocos compactos:** mostrar nome e resumo do conteúdo/configuração, sem expandir o formulário inteiro sobre o canvas.

### 4.3. Comportamentos obrigatórios

- Um fluxo novo inicia visualmente apenas com o bloco `Start`.
- Cada fluxo possui **um único** `Start`; ele não pode ser duplicado ou excluído.
- Os conectores permanecem visualmente associados a pontos fixos do bloco.
- Setas têm origem e destino identificáveis; saídas com significado diferente usam rótulos.
- Não permitir conexões estruturalmente inválidas, por exemplo, ligando uma saída a outra saída.
- O editor preserva as posições dos nós ao salvar/reabrir.
- A edição de um bloco não deve alterar silenciosamente as ligações de outros blocos.
- Ao remover uma saída ou bloco com ligações, avisar sobre as conexões que serão afetadas.
- Mudanças não publicadas não devem alterar imediatamente um fluxo que está rodando em produção.

## 5. Contrato dos tipos de bloco

| Bloco | Entradas | Saídas | Função |
|---|---:|---|---|
| `Start` | 0 | `Próximo passo` (1) | Início de cada fluxo |
| `Conteúdo` | 1 | `Próximo passo` (1) | Enviar mensagens/mídia com atrasos |
| `Aguardar Resposta` | 1 | `Respondeu`, `Não respondeu` (2) | Aguardar e armazenar resposta |
| `Operações` | 1 | `Próximo passo` (1) | Executar operações em sequência |
| `Ir para Fluxo` | 1 | **0** | Encerrar o fluxo atual e iniciar outro |
| `Condição` | 1 | `Sim`, `Não` (2) | Testar condições |
| `Menu` | 1 | Uma saída por opção + `Continuar` | Enviar pergunta com escolhas e aguardar seleção; `Continuar` ao vencer o prazo ou esgotar erros |

**Convenção técnica sugerida:** usar identificadores estáveis (`type`, `node_id`, `handle_id`) independentes do nome que aparece na tela. O frontend e o executor devem consumir o mesmo contrato validado pelo backend.

### 5.1. Bloco Start

- Criado automaticamente com o fluxo.
- Apenas indica o ponto de início da execução.
- Possui uma única saída para o próximo passo.
- Gatilhos (palavra-chave, boas-vindas e resposta padrão) são configurados **fora** do canvas.

### 5.2. Bloco Conteúdo

Permite combinar, **na mesma instância**, uma sequência ordenada de itens:

- Texto com editor simples: **negrito**, *itálico* e ~~tachado~~;
- Imagem;
- Vídeo;
- Áudio;
- Arquivo;
- Atraso entre itens.

Exemplo:

1. Texto: `Olá! Vou te enviar nosso catálogo.`
2. Atraso: `3 segundos`.
3. Imagem: catálogo.
4. Atraso: `2 segundos`.
5. Texto: `Qual produto te interessou?`

Cada item deve ser executado na ordem em que aparece no painel de edição.

O usuário citou atrasos curtos (por exemplo, entre **1 e 6 segundos**); **faixa, limite máximo e padrão definitivos ainda não foram fechados**. O atraso representa esperar antes de enviar o **próximo item**, não alterar o texto da mensagem já enviada.

Saída única: `Próximo passo`.

**Atenção:** `Aguardar Resposta` **não** fica dentro do bloco Conteúdo na solução final; é um bloco separado.

### 5.3. Bloco Aguardar Resposta

Campos de configuração:

- **Campo personalizado de destino:** selecionar ou criar campo vinculado ao lead.
- **Tempo máximo de espera:** valor configurável (ex.: 30 minutos, 2 horas, 24 horas).
- **Saídas:** `Respondeu` e `Não respondeu`.

Fluxo esperado:

1. A automação chega ao bloco e registra uma espera ativa para aquele contato/conexão.
2. Se uma mensagem válida para essa espera chegar antes do vencimento, armazena o conteúdo no campo do **respectivo lead** e segue pelo caminho `Respondeu`.
3. Se o prazo terminar sem resposta, segue por `Não respondeu` **sem preencher o campo com resposta inexistente**.
4. Uma resposta recebida **depois** de consumido ou expirado o prazo não reabre automaticamente a etapa encerrada.

**MVP de variáveis:** começar com campos personalizados do tipo **texto**. Outros tipos (número, data, data/hora, e-mail, CPF etc.) ficam para uma evolução posterior. A modelagem deve permitir expandir tipos futuramente.

**Distinção importante:** o campo é definido no workspace, mas seu **valor pertence ao lead**. Valores não podem ser compartilhados entre leads.

O motor deve garantir que `Respondeu` e `Não respondeu` sejam **mutuamente exclusivos**: apenas um deles pode vencer, inclusive se resposta e timeout ocorrerem quase simultaneamente.

### 5.4. Bloco Operações

Nome aprovado: **Operações**.

Permitir uma lista sequencial de operações dentro do mesmo bloco:

1. Adicionar etiqueta ao lead.
2. Remover etiqueta do lead.
3. Atualizar o valor de um campo personalizado do lead.
4. Abrir atendimento humano, escolhendo um atendente ou deixando a conversa **não atribuída**, disponível para a fila de atendimento. **Regra confirmada:** somente um gatilho/ação definido para abertura humana inicia esse atendimento; uma mensagem recebida não o inicia por si só.
5. Notificar membro da equipe via mensagem WhatsApp enviada pela conexão de atendimento.
6. Pausar automações para que o lead não receba respostas automatizadas durante a pausa.

Saída única: `Próximo passo`.

**Reutilizar regras do multiatendimento:** visibilidade, permissão para atribuir/transferir, seleção de membros, status e fila de conversas; não criar um sistema paralelo.

Para a notificação:

- Escolher membro autorizado da equipe.
- Usar o número de WhatsApp cadastrado para o membro e a conexão configurada.
- Tratar envio como operação sujeita às regras e limitações do provedor (não presumir que todo número aceita mensagens a qualquer momento).
- Mensagens enviadas pelo próprio sistema **não** devem ser interpretadas como mensagens recebidas para disparar palavras-chave.

**Regra mínima de segurança:** nenhuma execução, inclusive aquela com atraso ou timeout já agendado, pode enviar resposta automática enquanto a pausa estiver vigente. Jobs precisam conferir o estado da pausa antes de qualquer envio.

**Pendente:** decidir formalmente quando e como as automações pausadas voltam a funcionar. Há uma preferência inicial por liberar após o encerramento do atendimento humano, mas essa política não foi aprovada como regra definitiva. Também falta decidir se o relógio de um `Aguardar Resposta` em andamento fica congelado durante a pausa.

### 5.5. Bloco Ir para Fluxo

- Apenas uma entrada; **nenhuma saída**.
- No painel esquerdo, permitir pesquisar e selecionar um fluxo de destino **publicado, válido e pertencente ao workspace**.
- Quando alcançado, **encerrar definitivamente** a execução atual.
- Iniciar o fluxo de destino a partir de seu próprio `Start`, para o mesmo lead/conexão, sem retorno à origem.
- Preservar dados e campos personalizados do lead.
- Proibir referência a si próprio e ciclos entre fluxos publicados (`A → B → A`).
- Validar ciclos **na publicação** e aplicar limite de segurança também **em tempo de execução**, para proteger contra alterações concorrentes, configurações antigas ou falhas.

Não usar a pasta ou o nome do fluxo para identificar o destino; usar ID estável.

### 5.6. Bloco Condição

- Configurar uma ou mais verificações no painel lateral.
- Primeira categoria aprovada para o MVP: **presença de etiqueta no lead**.
- Permitir várias etiquetas verificadas na mesma condição.
- Operador único por bloco:
  - `Todas (E)`: cada verificação precisa ser verdadeira.
  - `Pelo menos uma (OU)`: basta uma verificação verdadeira.
- Exemplo com etiquetas `Brasil`, `Argentina` e `África`:
  - `Todas`: `Sim` apenas se possuir as três.
  - `Pelo menos uma`: `Sim` se possuir qualquer uma.
- Exatamente duas saídas: **`Sim`** e **`Não`**, independentemente da quantidade de verificações.
- O bloco não deve executar ações adicionais nem modificar etiquetas.

Condição sem verificações deve ser considerada **incompleta**, impedindo publicação.

### 5.7. Bloco Menu — decisão aprovada em 14/10/2026

**Objetivo:** apresentar escolhas ao lead no WhatsApp e encaminhar a execução para a saída da opção escolhida. É diferente do bloco `Aguardar Resposta`: o Menu não depende de salvar a resposta em campo personalizado e possui múltiplas saídas de roteamento.

#### Contrato funcional aprovado

- **Três formatos no mesmo tipo de bloco:** `Botões`, `Lista` e `Numérico`. O formato é configuração do bloco, sem criar três tipos de nó nem três motores de execução.
- **Uma entrada** e **uma saída estável para cada opção** configurada, mais **uma única saída `Continuar`**.
- `Continuar` é acionada quando se esgota o **tempo configurado** de espera **ou** se atinge o **limite configurado de respostas inválidas**. Não existem saídas separadas `Erro` e `Não respondeu`.
- Se a escolha for válida enquanto a espera estiver ativa, seguir **exclusivamente** pela saída da opção; não executar `Continuar` nesse caso.
- Resposta inválida: enviar o texto de erro configurado e permitir outra tentativa, respeitando o limite. A pergunta/menu poderá ser reapresentada nessa tentativa. Não permitir novos envios após a espera ter terminado.
- Se uma resposta e o vencimento do prazo ou limite de erros competirem, apenas **uma** transição vence, atomicamente.
- Ao interromper o fluxo ou iniciar atendimento humano, invalidar a espera e impedir que mensagens ou jobs atrasados a reativem, conforme as regras gerais do executor.

#### Diferenças entre os formatos

| Formato configurado | Apresentação | O que é aceito como escolha |
|---|---|---|
| **Botões** | Mensagem interativa com botões de resposta | Somente o evento de **seleção de botão** identificado pelo ID da opção; mensagem digitada, ainda que idêntica ao título, não seleciona nada. |
| **Lista** | Mensagem interativa com botão de abertura e itens selecionáveis | Somente o evento de **seleção de item da lista** identificado pelo ID da opção; texto digitado não seleciona nada. |
| **Numérico** | Mensagem de texto enumerando as opções | Somente número digitado que corresponda a uma das opções anunciadas; nomes das opções e outros textos não são aceitos. |

**Obrigação de implementação para Botões e Lista:** não usar igualdade de `message.text` com texto/rótulo do botão como prova de clique. Uma mensagem comum digitada como `Financeiro` ou `2` é **inválida** nesses dois formatos. O comportamento do usuário de não aceitar texto livre está aprovado; a capacidade de distinguí-lo no webhook real ainda requer homologação.

#### Campos no painel de edição (contrato de produto e propostas de UX)

1. **Formato do menu:** Botões, Lista ou Numérico — **aprovado**.
2. **Texto da pergunta** — **aprovado por implicação funcional**, usado como mensagem principal.
3. **Opções:** rótulo visível e ID interno estável, com saída correspondente; lista pode organizar itens em seções — **estratégia técnica recomendada**.
4. **Texto para resposta inválida**, **limite de erros** e **tempo máximo de espera** — **aprovados como funcionalidades configuráveis**, sem intervalo/valor padrão aprovado.
5. **Texto do botão de abertura da lista** e, quando necessário, nome das seções — **proposta de UX sujeita a validação do provedor**.
6. **Texto de rodapé e demais campos opcionais da UAZAPI** — não aprovados automaticamente como requisitos de UX.

O editor deve exibir os conectores das opções e `Continuar`, sem criar conexões silenciosas. Ao excluir ou reordenar opções, **preservar as ligações pelo ID estável da opção**, não pelo número/posição visual. Remover opção com conexão precisa avisar antes de afetar o grafo. O prazo não deve ser infinito por omissão: exigir um valor de espera válido para publicação (recomendação técnica para impedir automações presas).

#### Contrato técnico da UAZAPI — confirmado na documentação pública, **não homologado na instância**

- Endpoint oficial de envio: [`POST /send/menu`](https://docs.uazapi.com/endpoint/post/send~menu).
- Botões atuais: `type: "button"`, `choices` no formato `"Rótulo|id"`; `button_legacy` existe, mas só deve ser avaliado como variante de compatibilidade, se necessário.
- Lista: `type: "list"`, `listButton` e `choices` com itens `"Rótulo|id|descrição"` e possíveis marcadores de seção `[Seção]`.
- Numérico: enviar texto convencional via [`POST /send/text`](https://docs.uazapi.com/endpoint/post/send~text); **o Laravel** monta a enumeração e valida a resposta, não a UAZAPI.
- O modelo público de `Message` expõe `buttonOrListid` como **ID do botão/item de lista selecionado**, além de `messageType`, `messageid`, `text` e `content`: [`POST /message/find`](https://docs.uazapi.com/endpoint/post/message~find). Este dado sustenta a estratégia de distinguir clique de texto.
- O evento [`messages`](https://docs.uazapi.com/webhook/messages) entrega `message` normalizada, mas ressalva que **campos condicionais variam por mensagem/origem**. A página de webhook **não prova** que `message.buttonOrListid` sempre aparece em cliques de todos os tipos. **Teste real obrigatório** antes de ativar Botões e Lista.
- O provedor alerta que listas e botões podem ser descontinuados; não misturar botões de resposta com ações URL/ligação/cópia no Menu do MVP, por risco documentado de exibição no WhatsApp Web. URLs/chamadas não são opções de roteamento do bloco.

**Estratégia recomendada de validação do evento:** identificar conexão/workspace/lead; exigir um Menu ativo e uma escolha para essa **execução**; em Botões/Lista, exigir um ID de seleção interativa reconhecido e coerente com o tipo efetivamente enviado; validar que o ID pertence às opções do Menu vigente; rejeitar mensagem originada pela própria conta, seleção de um menu antigo, duplicata ou ID não reconhecido. Usar `message.id`/`message.messageid` para deduplicação/correlação e preservar o ID da mensagem enviada quando disponível. Não depender do texto exibido nem supor que `track_id` seja único. Detalhes do payload e da associação ao menu devem ser finalizados nos testes de homologação.

#### Fallback e falhas de envio

- **Aprovado:** se o envio de Botões ou Lista tiver **falha comprovada**, tentar a apresentação **Numérica**, mantendo as mesmas opções e respectivas saídas. A execução passa a aceitar números **somente se** o fallback numérico tiver sido efetivamente enviado.
- Um `200`/envio aceito pela API **não comprova** que os botões apareceram no dispositivo. Se o envio interativo parece bem-sucedido, mas não renderiza, **não há fallback automaticamente verificável** com as evidências disponíveis.
- Timeout de HTTP ou resultado incerto não prova falha: **não reenviar indiscriminadamente** para evitar menus duplicados. Consultar estado/correlação quando possível; estabelecer política de reconciliação antes da produção.
- Se nem o formato configurado nem o fallback puderem ser enviados com segurança, registrar **falha técnica de execução** (não fingir que houve timeout do lead, erro de escolha ou clique em `Continuar`). O comportamento de recuperação operacional exige teste e especificação antes de produção.
- Contabilização de erro e timeout começa após envio aceito/confirmado para processamento, conforme implementação homologada; reenviar por resposta inválida não deve reiniciar indefinidamente o relógio. A distinção entre `Sent` e `Delivered` não deve ser presumida como prova de renderização.

#### Limites, segurança e consistência

- Não inventar quantidade máxima de opções para Botões/Lista nem duração/erros padrão: limites do provedor, clientes WhatsApp e instância devem ser testados antes de impor validações específicas.
- Uma mensagem recebida deve consumir no máximo **uma** tentativa do Menu; não contar atualizações de status, eventos enviados pela própria API, webhook repetido, mensagens antigas nem reações técnicas como erros do lead.
- Para mensagens recebidas de conteúdo não selecionável (incluindo texto livre, quando o modo requer clique), usar a política de resposta inválida; definir o tratamento de mídia/áudio no teste do MVP e evitar armazenar seu conteúdo desnecessariamente.
- Guardar apenas estado operacional indispensável (`execution_id`, `node_id`, `selected_option_id` quando houver, ID da mensagem associada, contador de erros, `expires_at` e deduplicação), **sem persistir corpo do WhatsApp**, conforme a política já aprovada no chat.
- Palavras-chave elegíveis para **interromper fluxo ativo** continuam tendo a prioridade já definida antes do consumo de uma mensagem pelo Menu; atendimento humano e pausas impedem o Menu de responder indevidamente.

#### Homologação bloqueadora e cenários de aceite

- Confirmar em instância real e em aparelhos relevantes que cliques em **Botões** e **Lista** chegam no webhook `messages` com seleção identificável (`buttonOrListid` ou outro campo inequívoco), diferenciáveis de mensagem de texto com o mesmo rótulo.
- Confirmar que uma escolha de menu antigo não seleciona opção de menu novo, e que duplicatas/atrasos não geram duas transições.
- Validar limites de botões/itens, visualização no WhatsApp Web e no celular, respostas inválidas, tempo limite, falhas de envio, reconexão e fallback numérico de **falha confirmada**.
- Se o campo inequívoco de clique não existir no ambiente real, **não simular clique pela comparação de texto**: bloquear ou sinalizar indisponibilidade dos formatos interativos até adaptar/homologar o contrato do provedor. O formato Numérico segue possível.

## 6. Configurações de entrada por conexão WhatsApp

Exibir opções de automação no contexto de cada **conexão de WhatsApp** do workspace:

1. **Fluxo de boas-vindas:** selecionar um fluxo normal publicado ou deixar desabilitado.
2. **Fluxo de resposta padrão:** selecionar um fluxo normal publicado ou deixar desabilitado.
3. **Palavras-chave:** cadastro ordenável de regras que podem iniciar fluxos.

Todas as configurações e referências devem estar isoladas por **workspace** e, quando aplicável, por **conexão WhatsApp**.

### 6.1. Primeiro contato e boas-vindas

- `Primeiro contato` significa a **primeira mensagem recebida** daquele lead naquela conexão WhatsApp; não equivale a reabrir a conversa ou mandar mensagem depois de semanas.
- Boas-vindas deve disparar **no máximo uma vez por lead por conexão**.
- A primeira mensagem é classificada **antes** de escolher o fluxo: se houver palavra-chave elegível, ela tem prioridade sobre boas-vindas.
- Se a primeira mensagem disparar uma palavra-chave, **não enviar boas-vindas depois** como compensação; o primeiro contato já ocorreu.
- O fluxo de boas-vindas continua sendo um fluxo reutilizável, podendo ser executado mais tarde por palavra-chave ou `Ir para Fluxo`, sem que isso signifique um segundo disparo do **gatilho automático de primeiro contato**.
- Evitar resetar acidentalmente o histórico de primeiro contato ao encerrar, transferir ou reabrir a conversa.

### 6.2. Resposta padrão

Quando uma **mensagem recebida** não disparar palavra-chave:

- Se for o primeiro contato e existir boas-vindas configurado, inicia boas-vindas.
- Se o contato **já interagiu anteriormente**, **não houver fluxo ativo** e as automações **não estiverem pausadas**, inicia o fluxo de resposta padrão, se configurado.
- Se já houver uma automação ativa, **não** iniciar resposta padrão; respeitar o estado atual.
- Se automações estiverem pausadas para atendimento humano, **não** iniciar resposta padrão.

**Nota:** resposta padrão não é uma resposta de texto fixa; é um **fluxo normal selecionado** para esse papel.

### 6.3. Palavras-chave: formulário e lista

Cada regra deve conter, no mínimo:

| Campo | Comportamento |
|---|---|
| Palavra-chave / expressão | Texto informado pelo administrador |
| Tipo de correspondência | `É igual a`, `Começa com`, `Contém` |
| Fluxo de destino | Selecionar fluxo publicado do workspace |
| Interromper fluxo em andamento | `Ligado` ou `Desligado`; **desligado por padrão** |
| Posição de prioridade | Determinada pela ordem na lista |

A tela de **Palavras-chave** precisa permitir **reordenar as regras visualmente** (por exemplo, arrastar e soltar). Salvar essa ordem de forma persistente.

Mostrar aviso curto na interface:

> **As palavras-chave são verificadas de cima para baixo. A primeira regra aplicável tem prioridade.**

#### Interpretação dos operadores

- `É igual a`: mensagem inteira igual à expressão configurada.
- `Começa com`: mensagem começa pela expressão.
- `Contém`: a expressão aparece em qualquer posição da mensagem.
- Comparação sem diferenciar letras maiúsculas/minúsculas ou acentos.
- Para tornar os resultados previsíveis, **recomendação técnica**: remover espaços nas pontas e normalizar Unicode antes da comparação; não usar IA nem correspondência semântica no MVP.

Exemplos com o termo `curso`:

| Mensagem recebida | É igual a | Começa com | Contém |
|---|:---:|:---:|:---:|
| `Curso` | Sim | Sim | Sim |
| `curso de inglês` | Não | Sim | Sim |
| `Quero um curso` | Não | Não | Sim |
| `cursos` | Não | Sim | Sim |

**Importante:** `Contém` e `Começa com` fazem **correspondência textual**, não identificação de palavra inteira; o exemplo `cursos` acima é proposital.

#### Ordem e elegibilidade

A ordem na lista é a **única prioridade entre regras elegíveis**: não implementar heurísticas de "termo mais longo", "mais específico" ou "operador exato ganha".

- Quando **não há fluxo ativo**, são elegíveis as regras que correspondem à mensagem.
- Quando **há fluxo ativo**, só são elegíveis as regras que correspondem **e** estão marcadas como `Interromper fluxo em andamento = Ligado`.
- As regras são avaliadas de cima para baixo; **apenas a primeira elegível** dispara. Uma mensagem nunca inicia dois fluxos.
- Regra que corresponde, mas não tem autorização para interromper, **não encerra** o fluxo em andamento. A varredura pode continuar até encontrar uma regra elegível.
- Quando as automações do lead estiverem **pausadas**, nenhuma palavra-chave dispara, independentemente da posição e da flag de interrupção.

Se uma regra interromper:

1. Finalizar a execução anterior como **interrompida**, cancelando seus aguardos/continuações pendentes.
2. Iniciar o fluxo escolhido **uma única vez**.
3. Nunca deixar jobs antigos retomarem a execução interrompida.

#### Mensagens recebidas durante uma espera por resposta

A chegada de uma mensagem quando existe `Aguardar Resposta` ativo deve respeitar a ordem:

1. Verificar pausa: se as automações estão pausadas, **não** acionar palavras-chave e **não** produzir mensagens automáticas. A política de armazenamento da resposta durante a pausa continua pendente.
2. Se não está pausado, procurar primeiro uma **palavra-chave elegível para interromper** o fluxo. Se encontrada, ela vence, interrompe a execução atual e a mensagem **não** é usada para preencher o campo da pergunta interrompida.
3. Se não houver palavra-chave interruptiva elegível, entregar a mensagem à espera pendente e seguir por `Respondeu`, se o prazo ainda estiver válido.
4. Se a espera já expirou, não reabri-la. Para mensagens sem execução ativa, aplicar a classificação normal de palavras-chave e resposta padrão.

Isso evita que a mesma mensagem simultaneamente dispare outro fluxo e seja salva como resposta do fluxo antigo.

### 6.4. Ordem geral de decisão para mensagem recebida

```text
Mensagem recebida da conexão WhatsApp
          |
          v
Registrar evento com idempotência + identificar workspace/lead/conexão
          |
          v
Automações deste lead estão pausadas?
    | Sim                       | Não
    v                           v
Não dispara fluxo       Há automação ativa?
                        | Sim                | Não
                        v                    v
                 Procurar primeira      Procurar primeira
                 palavra-chave          palavra-chave
                 correspondente         correspondente
                 com "interromper"      na ordem da lista
                 ligado
                 | Achou | Não          | Achou | Não
                 v       v              v       v
              Interrompe  Mantém       Inicia   É primeiro
              e inicia    execução     fluxo    contato?
              novo fluxo  atual*                 | Sim  | Não
                                               v      v
                                           Boas-     Resposta
                                           vindas    padrão
```

(* Se a execução atual estiver no bloco `Aguardar Resposta`, o processamento da resposta continua normalmente conforme a regra anterior.)

Os gatilhos de boas-vindas/resposta padrão só executam se o respectivo fluxo estiver configurado e publicado. Se não estiver, não iniciar fluxo automaticamente.

> **Regra de concorrência:** a checagem de fluxo ativo, pausa, ordem de palavras-chave e decisão de disparo precisam observar estado consistente do mesmo lead/conexão.

## 7. Estados e execução no Laravel

### 7.1. Separação de responsabilidades

- **Vue Flow**: apenas desenho, edição e serialização do grafo.
- **Laravel**: valida grafo, configurações e permissões; publica versões imutáveis; decide gatilhos; percorre nós; manipula lead/conversa; dispara mensagens.
- **Filas/Horizon**: realizam trabalho assíncrono, envios, atrasos e timeouts.
- **Banco**: fonte de verdade do estado, resultados, registros de primeiro contato, prioridades e execução.

O runtime não deve depender de uma aba do navegador aberta.

### 7.2. Modelo lógico sugerido (não é migration definitiva)

Entidades ou tabelas lógicas:

| Entidade | Dados relevantes |
|---|---|
| Pastas | `id`, `workspace_id`, `parent_id`, `name` |
| Fluxos | `id`, `workspace_id`, `folder_id` opcional, `name`, `status`, versão publicada |
| Versões de fluxo | `flow_id`, `version`, `nodes`, `edges`, data de publicação |
| Configuração de entrada | `workspace_id`, `connection_id`, `welcome_flow_id`, `default_flow_id` |
| Regras de palavra-chave | `workspace_id`, `connection_id`, `term`, `operator`, `flow_id`, `priority`, `interrupt_active` |
| Primeiro contato | `workspace_id`, `connection_id`, `lead_id`, `first_inbound_at` ou registro equivalente |
| Execuções | `flow_id`, `flow_version`, `lead_id`, `connection_id`, `current_node`, `status`, timestamps |
| Esperas de resposta | `execution_id`, `node_id`, `field_id`, `expires_at`, `status`, mensagem consumida |
| Esperas de Menu | `execution_id`, `node_id`, IDs estáveis de opções/saídas, formato efetivamente enviado, `expires_at`, tentativas inválidas, referência da mensagem enviada e evento consumido |
| Pausa de automações | Estado persistente do lead/conexão e motivo/origem da pausa |
| Log de eventos | IDs de mensagens/webhooks processados, transições de execução e erros |

**Não criar tabelas duplicadas** para lead, etiquetas, campos personalizados, membros ou conversas se elas já existirem no SaaS. Adaptar essas entidades à modelagem atual antes de escrever migrations.

### 7.3. Estados sugeridos

- **Fluxo (editor):** `draft` e `published`, mantendo versão publicada estável enquanto há alterações.
- **Execução:** `running`, `waiting_reply`, `completed`, `timed_out` (estado de espera ou evento), `interrupted`, `failed`.
- **Pausa do lead:** estado próprio separado da execução; pausar um lead não deve apagar seus campos ou histórico.

Os nomes internos podem seguir as convenções do repositório, preservando a semântica.

### 7.4. Garantias de robustez indispensáveis

1. **Isolamento multiworkspace:** IDs de pastas, fluxos, contatos, membros, etiquetas e campos devem ser validados sempre pelo workspace da operação.
2. **Idempotência:** reenvio de webhook ou retry de fila não pode duplicar disparos, resposta capturada ou mensagens enviadas por erro de controle de estado.
3. **Concorrência:** duas mensagens simultâneas de um lead não podem iniciar dois fluxos conflitantes.
4. **Timeout atômico:** resposta e vencimento disputam a mesma espera, garantindo apenas uma saída.
5. **Jobs obsoletos:** antes de qualquer envio ou continuação, verificar se a execução ainda está ativa e se o passo esperado ainda é o atual.
6. **Versões:** execuções devem usar definição publicada consistente; edição de rascunho não altera nós de execução corrente.
7. **Limites de segurança:** impedir ciclos de `Ir para Fluxo`, reentrância infinita e avalanche de tarefas.
8. **Observabilidade:** registrar causa do disparo (`keyword`, `welcome`, `default`, `flow_jump`), regra aplicada, versão, bloco, erro e resultado.
9. **Falhas externas:** tratar indisponibilidade e restrições da API WhatsApp sem presumir entrega bem-sucedida.
10. **Segurança dos campos:** nunca permitir salvar resposta do contato A no campo do contato B, nem acessar recursos de outro workspace.
11. **Mídias:** respeitar armazenamento e autorização de acesso já utilizados pelo SaaS; não assumir URLs públicas para arquivos privados.

## 8. Regras de publicação e validação

O editor pode salvar rascunho incompleto. Para **publicar**, o backend deve recusar:

- Fluxo sem `Start` único e sem encadeamento válido a partir dele.
- Conexões com entradas/saídas inexistentes ou IDs repetidos.
- `Aguardar Resposta` sem campo ou prazo, ou sem os conectores `Respondeu` e `Não respondeu` no esquema do nó.
- `Menu` sem formato suportado, pergunta, opções com identificadores únicos, prazo e limite de erros válidos, ou sem os conectores de cada opção e `Continuar` no esquema do nó. Validação de quantidade máxima por formato depende da compatibilidade homologada.
- `Condição` sem verificações ou sem os conectores `Sim` e `Não` no esquema do nó.
- `Ir para Fluxo` sem destino válido, publicado e do mesmo workspace.
- Referência direta ou indireta que crie ciclo entre fluxos por `Ir para Fluxo`.
- Mídias ou operações incompletas necessárias à execução.

**Ponto de UX:** apresentar mensagens de erro específicas no bloco correspondente, não apenas erro genérico de publicação.

### 8.1. Comportamento do grafo

- Blocos não ligados ao caminho iniciado em `Start` não devem executar.
- **Recomendação técnica:** avisar sobre nós desconectados; decidir posteriormente se bloqueiam publicação.
- **Fim de ramo sem próximo bloco:** como não foi definido um bloco `Fim`, **deixar em aberto** a regra de publicação e execução de saídas não conectadas. A proposta mais simples é concluir normalmente o ramo, mas ela precisa de confirmação antes do executor definitivo.
- Um nó `Ir para Fluxo` é terminal: não pode possuir saída.
- O mecanismo de execução deve garantir que cada transição utilize um `handle_id` reconhecido pelo backend.

## 9. Cenários de aceite

| ID | Cenário | Resultado esperado |
|---|---|---|
| AC01 | Criar fluxo | Canvas inicia apenas com `Start` |
| AC02 | Clicar `+` na saída de `Start` e selecionar `Conteúdo` | Bloco criado já conectado e painel esquerdo aberto |
| AC03 | Adicionar texto, atraso e imagem num `Conteúdo` | Envio ocorre na ordem configurada |
| AC04 | `Aguardar Resposta` recebe mensagem antes do prazo | Salva no campo **do lead correto** e segue `Respondeu` |
| AC05 | `Aguardar Resposta` atinge o prazo | Não escreve valor inventado e segue `Não respondeu` |
| AC06 | Resposta e timeout competem | Apenas um caminho continua |
| AC07 | Condição `Todas (E)` para 3 etiquetas, lead possui 2 | Segue `Não` |
| AC08 | Condição `Pelo menos uma (OU)`, lead possui 1 das 3 | Segue `Sim` |
| AC09 | `Ir para Fluxo` vai para outro publicado | Encerra origem, inicia destino no `Start`, sem retorno |
| AC10 | Fluxo A redireciona a B e B redireciona a A | Publicação é bloqueada com erro explicativo |
| AC11 | Primeiro contato sem palavra-chave | Executa boas-vindas **uma única vez** |
| AC12 | Primeiro contato com palavra-chave correspondente | Palavra-chave prevalece; boas-vindas não é enviada depois |
| AC13 | Contato antigo sem fluxo ativo nem palavra-chave | Inicia resposta padrão configurada |
| AC14 | Contato está aguardando resposta | Resposta padrão **não** dispara |
| AC15 | Mensagem corresponde a duas regras | Apenas a regra elegível mais acima na lista dispara |
| AC16 | Administrador muda a ordem | Novo desempate respeita imediatamente a ordem persistida |
| AC17 | Regra `Contém` para `curso` | `Quero um curso` corresponde |
| AC18 | Regra `É igual a` para `curso` | `Quero um curso` não corresponde |
| AC19 | Palavra-chave com `Interromper = desligado` durante execução ativa | Não cancela a execução |
| AC20 | Palavra-chave com `Interromper = ligado` durante execução ativa | Cancela execução anterior e inicia a nova uma única vez |
| AC21 | Automações pausadas | Não dispara palavra-chave, boas-vindas ou resposta padrão |
| AC22 | Mover fluxo de pasta | IDs/referências e regras associadas continuam válidos |
| AC23 | Lead A responde a campo personalizado | Campo do lead B permanece inalterado |
| AC24 | Entrega repetida do mesmo webhook | Não gera novo disparo duplicado |
| AC25 | Editar rascunho de fluxo publicado | Versão em produção não muda até nova publicação |
| AC26 | Menu Botões: lead clica em opção válida | Segue somente a saída vinculada ao ID selecionado, após validação de webhook real |
| AC27 | Menu Lista: lead seleciona item válido | Segue somente a saída do item escolhido, após validação de webhook real |
| AC28 | Menu Botões/Lista: lead digita exatamente o texto ou número exibido | Não é tratado como clique; conta como resposta inválida elegível |
| AC29 | Menu Numérico: lead digita número cadastrado | Segue somente a saída correspondente |
| AC30 | Menu: limite de erros atingido | Executa apenas `Continuar`, mesmo que exista timeout concorrente |
| AC31 | Menu: tempo termina antes de escolha válida | Executa apenas `Continuar` |
| AC32 | Menu: click/resposta e timeout simultâneos | Estado atômico permite uma única saída |
| AC33 | Menu interativo falha comprovadamente no envio | Tenta fallback numérico; aceita números só após envio do fallback |
| AC34 | Menu interativo aceito pela UAZAPI, mas sem exibição demonstrável | Não assume automaticamente que houve falha ou que texto digitado equivale a clique |
| AC35 | Mensagem duplicada ou clique em menu obsoleto | Não altera menu ativo nem consome duas vezes o mesmo evento |

## 10. Pendências explícitas (não transformar em decisões sem alinhamento)

### 10.1. Decisões funcionais ainda abertas

**Regras já aprovadas no chat:** finalizar libera o responsável ativo e preserva o último atendente e histórico. Nova mensagem só abre atendimento humano se passar por um gatilho que o determine; fora disso, segue o processamento normal de palavras-chave e fluxos. Responsável ou administrador pode responder, finalizar e criar notas. Isso não define a retomada automática das automações. O cadastro inicial contém nome, email, CPF e notas; etiquetas e campos personalizados necessários aos blocos continuam sendo dependências da fase de fluxos.

1. **Retomada após pausa:** encerrar atendimento humano reativa automações automaticamente? Em quais circunstâncias? Uma pausa manual também seria revertida?
2. **Escopo da pausa:** por lead no workspace inteiro ou por lead/conexão WhatsApp? A implementação deve impedir que o robô responda indevidamente por outra conexão.
3. **Atendimento humano:** abrir atendimento deve automaticamente pausar o robô ou isso exige a operação explícita `Pausar automações`?
4. **Sem boas-vindas configurado no primeiro contato:** manter silêncio automático ou permitir cair no fluxo de resposta padrão?
5. **Mensagem recebida durante execução ativa que não esteja aguardando resposta:** registrar sem afetar fluxo, enfileirar ou entregar a algum mecanismo específico?
6. **Captura no `Aguardar Resposta`:** mensagens de áudio, anexos e respostas sucessivas; inicialmente priorizar texto.
7. **Sobrescrita de campo personalizado:** se já houver valor, substituir por padrão ou oferecer opções?
8. **Faixa e valor padrão** de atrasos e do timeout de resposta.
9. **Tratamento de falha em operação ou envio de mídia:** continuar, parar ou oferecer uma saída de erro?
10. **Teste de fluxo:** como simular respostas, envios e integrações sem disparar WhatsApp real.
11. **Permissões exatas:** quais papéis podem criar, editar, publicar e mudar os gatilhos.
12. **Regra para fluxos publicados removidos/desativados:** o que fazer com referências e execuções já iniciadas.
13. **Saída final sem ligação:** permitir como encerramento implícito ou exigir um bloco terminal? Recomendação: encerramento implícito.
14. **Pausa durante espera:** congelar o timeout, deixá-lo vencer sem envio, ou cancelar a espera?
15. **Persistência de resposta capturada:** o bloco Aguardar Resposta prevê salvar conteúdo em campo do contato. Formalizar a fronteira dessa captura com a decisão do chat de não persistir conteúdo/histórico de mensagens; não presumir uma exceção geral.
16. **Menu — homologação dos interativos:** verificar IDs efetivos nos webhooks de Botões/Lista e correlação de seleções em instância contratada; se não houver evento inequívoco, não habilitar reconhecimento baseado em texto.
17. **Menu — limites e defaults:** quantidade de opções por tipo, duração padrão/máxima e limite padrão/máximo de erros precisam de validação no provedor e no produto. Não inferir valores da imagem de referência.
18. **Menu — falha operacional:** detalhar política de reconciliação quando envio tiver resultado indeterminado e quando o fallback também falhar; não converter falha de infraestrutura em `Continuar` silenciosamente.

O acompanhamento das decisões compartilhadas está no [registro de decisões](../planejamento/decisoes.md).

### 10.2. Recomendações de engenharia ainda não aprovadas como UX

- Edição em rascunho com publicação versionada, preferencialmente imutável.
- Confirmação visual antes de remover nós que possuam conexões.
- Aviso (não necessariamente bloqueio) para nós desconectados.
- Normalização de espaços/Unicode no avaliador de palavras-chave.
- Logs consultáveis por execução para suporte e diagnóstico.
- Salvar alterações no editor com controle de concorrência e indicação de estado.

## 11. Ordem de entrega e etapas do editor

**Prioridade aprovada:** primeiro implementar o chat e multiatendimento descritos na [especificação de chat](../modulos/chat-multiatendimento.md). As etapas abaixo pertencem à fase posterior de fluxos; não substituem essa prioridade.

**Etapa 1 da fase de fluxos — Protótipo visual:** criar página do editor com Vue Flow, nó `Start`, menu de blocos, painel lateral esquerdo e `+` no conector que insere automaticamente um bloco `Conteúdo` já conectado. Persistir somente o grafo necessário ao protótipo. Validar UX em desktop.

**Etapa 2 — Persistência/editor:** criar biblioteca com pastas/subpastas, edição dos seis blocos funcionais (incluindo Menu), conexões, rascunhos, validação e publicação; somente habilitar formatos interativos depois da homologação da UAZAPI.

**Etapa 3 — Executor:** motor de execução no Laravel, envios e atrasos com filas/Horizon, captura de resposta com timeout atômico e transições.

**Etapa 4 — Gatilhos:** configurações por conexão, boas-vindas, resposta padrão, palavras-chave ordenáveis e concorrência/pausas.

**Etapa 5 — Integração operacional:** etiquetas, campos, atendimento humano, notificações e monitoramento de execuções.

> As etapas são uma sugestão de planejamento, não autorização para implementar funcionalidades em produção antes de confirmar as pendências críticas de comportamento.

## 12. Referências e fronteiras

- Vue Flow: <https://vueflow.dev/>
- [Chat e multiatendimento](../modulos/chat-multiatendimento.md).
- [Integração UAZAPI](../integracoes/uazapi.md) e [documentação oficial](https://docs.uazapi.com/).
- O código existente do SaaS deve prevalecer sobre nomes e tabelas **sugeridos** aqui; adaptações estruturais devem ser documentadas antes de implementadas.
- Qualquer ampliação do escopo inicial deve atualizar esta especificação e marcar o que passou de **pendente** para **aprovado**.


## 13. Histórico de versões

- **v0.4 — 2026-10-14:** incluído bloco Menu com formatos Botões/Lista/Numérico, saídas por opção + `Continuar`, seleção interativa obrigatória, fallback numérico em falha confirmada, correlação/idempotência, limites e homologação pendentes; fontes oficiais da UAZAPI diferenciadas de testes operacionais.

- **v0.3 — 2026-10-12:** sincronizada entrada humana por gatilho, atores do atendimento e cadastro mínimo aprovado; mantidas pendências de pausa/retomada e recursos de CRM necessários ao executor.

- **v0.2 — 2026-10-12:** confirmados UAZAPI, um número por workspace, Vue 3 + Vue Flow e chat antes dos fluxos; registrada dependência da finalização humana e pendência sobre captura de respostas.
- **v0.1 — 2026-10-12:** especificação inicial do editor, blocos, executor e gatilhos.
