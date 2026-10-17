# chAIt — Especificação do bloco IA

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Versão:** 0.1  
> **Data:** 12/10/2026  
> **Destino no repositório:** `docs/modulos/bloco-ia.md`  
> **Estado:** implementado e testado localmente; homologação real parcial com OpenAI/UAZAPI registrada na evolução Assistente IA, com entradas sintéticas e CRM isolado; não certifica todos os cenários ponta a ponta.  
> **Público:** desenvolvimento, produto e QA.

## 1. Objetivo e relação com o projeto

Adicionar ao editor um bloco **IA**, no qual o lead conversa com a inteligência artificial até que ela cumpra o papel definido pelo administrador ou encaminhe a conversa para atendimento humano.

O prompt e o modelo são configurados no bloco. A execução acontece no backend Laravel, usando a chave OpenAI do cliente. Enquanto a conversa estiver no bloco, o fluxo permanece nessa etapa e mantém o contexto necessário à conversa.

O responsável pelo produto informou que a base de chat e fluxos já está implementada e em testes. Este documento especifica a evolução IA; não é uma auditoria do código existente.

Documentos relacionados, com links relativos à pasta canônica `docs/modulos/`:

- [Editor de fluxos](editor-de-fluxos.md).
- [Chat e multiatendimento](chat-multiatendimento.md).
- [Registro de decisões](../planejamento/decisoes.md).
- [Integração UAZAPI](../integracoes/uazapi.md).

As decisões desta conversa acrescentam o bloco IA ao escopo anterior. A indicação de IA como fora do escopo no briefing antigo deve ser atualizada quando esta evolução for incorporada ao repositório.

## 2. Decisões aprovadas

| ID    | Decisão                                                                                                                                                                                 |
| ----- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| IA-01 | O lead conversa com a IA dentro do bloco, com instruções/prompt configuradas nesse bloco.                                                                                               |
| IA-02 | A conclusão é solicitada por gatilho interno/tool, cujo resultado é aplicado pelo executor.                                                                                             |
| IA-03 | Existem somente duas saídas: **Continuar** e **Atendimento Humanizado**.                                                                                                                |
| IA-04 | **Continuar** significa que a IA cumpriu seu papel e concluiu o bloco. O administrador define a continuidade conectando outro bloco; sem conexão nessa saída, a execução atual termina. |
| IA-05 | **Atendimento Humanizado** é um gatilho para abrir atendimento humano, reutilizando o módulo existente.                                                                                 |
| IA-06 | O contexto/thread existe somente enquanto aquela sessão estiver no bloco. Ao sair, é descartado; uma nova entrada começa do zero.                                                       |
| IA-07 | O cliente fornece sua própria chave OpenAI e arca com o consumo na conta correspondente. A chave precisa de configuração protegida no workspace.                                        |
| IA-08 | O modelo é escolhido no bloco; outros parâmetros podem ser acrescentados quando necessários e compatíveis.                                                                              |
| IA-09 | O prazo máximo de inatividade é configurável no bloco. Novas mensagens do lead reiniciam a contagem.                                                                                    |
| IA-10 | Ao expirar, o administrador escolhe **Encerrar o fluxo atual** ou **Abrir atendimento humanizado**.                                                                                     |
| IA-11 | A ação padrão de expiração é **Encerrar o fluxo atual**. A expiração não significa objetivo cumprido e não aciona **Continuar**.                                                        |
| IA-12 | A conclusão deve acontecer uma única vez e invalidar respostas/tarefas pendentes da sessão encerrada.                                                                                   |

Não existe uma terceira saída **Encerrar** no canvas. Encerrar por inatividade é uma ação interna de expiração.

## 3. Configuração na interface

### 3.1. No bloco IA

| Campo                           | Comportamento                                                                                                                |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| **Instruções da IA / Prompt**   | Define papel, informações disponíveis, limites da conversa e quando solicitar cada saída.                                    |
| **Modelo OpenAI**               | Seleciona um modelo compatível com a integração e com as tools necessárias. Validar disponibilidade para a conta do cliente. |
| **Tempo máximo de inatividade** | Define quanto tempo a sessão pode permanecer sem novas mensagens do lead.                                                    |
| **Quando o prazo expirar**      | Seleção entre **Encerrar o fluxo atual** e **Abrir atendimento humanizado**; padrão: encerrar.                               |

O prompt contém os critérios de conclusão. Não foi aprovada a obrigatoriedade de um segundo campo separado “Quando concluir”.

**Contrato implementado:** exigir configuração válida antes da publicação e mostrar os erros no bloco. A interface usa segundos, de 300 a 604800, com padrão de 3600. O limite opcional de mensagens aceita 1 a 200; vazio significa sem limite.

O formulário deve seguir o editor existente, com painel lateral e conectores identificáveis. A opção de modelo não autoriza expor automaticamente todos os parâmetros da API. Parâmetros adicionais precisam ser úteis à operação e suportados pelo modelo selecionado.

### 3.2. Chave OpenAI no workspace

Local recomendado: **Configurações do workspace → Integrações → OpenAI**.

- A chave pertence à configuração daquele workspace e é usada pelos seus blocos IA.
- A conta do cliente é a origem das chamadas e do consumo; não usar silenciosamente uma chave de outro workspace ou uma chave geral do SaaS como fallback.
- Proteger a chave no backend com armazenamento de segredo adequado ou criptografia em repouso.
- Depois de cadastrada, não devolver a chave completa à interface nem registrá-la em logs, filas ou mensagens de erro.
- Recomenda-se permitir gestão da integração apenas ao administrador do workspace e oferecer substituição e remoção da chave.
- Modelo, prompt e demais configurações do bloco permanecem separados da credencial.

Os detalhes da tela e do armazenamento são escolhas de engenharia; não foi aprovado um novo sistema comercial de compra ou revenda de tokens.

## 4. Saídas e significado no executor

| Saída                      | Efeito                                                                                                                            |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| **Continuar**              | Fecha a sessão IA, descarta o contexto e percorre a conexão dessa saída. Sem conexão, conclui a execução atual do fluxo.          |
| **Atendimento Humanizado** | Fecha a sessão IA, descarta o contexto e solicita abertura humana pelo serviço existente de atendimento. A IA deixa de responder. |

### 4.1. Enquanto conversa

- Mensagens novas elegíveis são destinadas à sessão IA ativa, respeitando pausas e interrupções do executor existente.
- A conversa continua dentro do bloco; não se percorre **Continuar** a cada resposta da IA.
- Uma resposta de texto normal não encerra o bloco por si só.
- A execução ativa impede que a resposta padrão seja disparada como se não houvesse fluxo em andamento.
- Mensagens enviadas pelo sistema, webhooks duplicados e sincronizações de histórico não devem ser confundidos com novas mensagens do lead.

### 4.2. Atendimento humano

A saída **Atendimento Humanizado** deve realmente acionar a abertura humana. Não depender de o administrador acrescentar um bloco Operações para realizar essa abertura.

Reutilizar estado, permissões, fila e serviços do multiatendimento existente. A sessão IA encerrada não pode continuar respondendo enquanto a conversa aguarda ou recebe atendimento humano.

O conector humano representa esse encaminhamento; não deve provocar avanço automático para outro bloco que continue respondendo ao lead depois da abertura humana. A representação visual dessa ação deve seguir os componentes do editor existente.

Este documento não cria distribuição automática, escolha de atendente no bloco IA ou novas regras de retomada após finalização humana. Aplicar o contrato vigente do multiatendimento.

### 4.3. Exemplo de uso

Um prompt orienta a IA a descobrir o curso de interesse e confirmar a escolha com o lead. Quando cumprir esse objetivo, solicita **Continuar**. O administrador pode conectar essa saída a um Conteúdo com orientações de matrícula ou deixá-la sem conexão para concluir o fluxo.

Se a conversa precisar de atendimento humano conforme as instruções, a IA solicita **Atendimento Humanizado**. A abertura ocorre pelo módulo existente e a sessão IA termina.

## 5. Tool interna de conclusão

**Contrato técnico sugerido:** uma tool `concluir_bloco_ia`, com um argumento `saida` limitado aos valores `continuar` e `atendimento_humanizado`. Nome interno e formato final podem seguir as convenções do repositório; os rótulos visíveis permanecem os aprovados.

A IA solicita a ação; o Laravel valida e executa a transição. A OpenAI documenta que o código de uma function tool é executado pela aplicação, a partir da chamada recebida do modelo [F-01].

O executor deve verificar:

1. A chamada pertence à sessão, execução, bloco e workspace corretos.
2. A sessão ainda está ativa e a execução ainda está nesse bloco.
3. O resultado pertence às duas saídas permitidas.
4. Nenhuma conclusão, expiração ou interrupção já venceu a disputa.
5. A ação respeita o estado atual do atendimento e das automações.

Workspace, contato, execução, nó e destinos são derivados do estado confiável do backend. A tool não deve aceitar IDs arbitrários de destino escolhidos pelo modelo.

Não inferir conclusão a partir de frases como “finalizamos” ou “vou encaminhar”. Usar a ação estruturada e a transição do executor.

**Limite importante:** a validade dos argumentos não comprova que a IA interpretou corretamente o objetivo. O prompt deve explicar quando concluir e quando encaminhar; QA deve testar conclusões prematuras e pedidos do lead que tentem alterar essas regras. Não exigir que o dev invente uma validação determinística de um objetivo descrito apenas em linguagem natural.

## 6. Contexto e ciclo de vida

### 6.1. Sessão nova a cada entrada

Vincular a sessão a **workspace, conexão, contato, execução do fluxo e instância da entrada no bloco**. O mesmo contato pode entrar novamente no mesmo nó e deve receber uma sessão nova.

“Thread” é o conceito de contexto da conversa, não uma exigência de usar um endpoint específico ou uma implementação antiga da OpenAI.

- Manter o contexto necessário às interações da sessão ativa.
- Não misturar sessões de contatos, workspaces ou entradas distintas.
- Não recuperar o diálogo de uma sessão encerrada quando o lead voltar ao bloco.
- Não importar automaticamente todo o histórico anterior do WhatsApp para uma nova sessão.
- Manter a configuração publicada do bloco consistente durante a execução; alterações no rascunho não mudam silenciosamente a sessão ativa.

### 6.2. Descarte

Descartar o contexto reutilizável ao concluir por qualquer saída, expirar, cancelar a execução ou interromper a sessão pelo atendimento humano/por mecanismo existente.

O descarte deve invalidar callbacks, respostas em processamento e timers antigos. Uma sessão nova não pode receber a resposta atrasada de uma sessão anterior.

Metadados operacionais podem permanecer: identificador da sessão, modelo, horários, causa do encerramento, resultado e consumo informado pela API. Esses registros não devem conter o diálogo completo nem um resumo textual que o reconstrua.

### 6.3. Compatibilidade com a política de mensagens

A regra de não criar histórico permanente completo de mensagens WhatsApp no Laravel continua válida. A memória necessária à sessão IA é limitada ao período em que ela está ativa e precisa de descarte efetivo. No modo de tempo real, entradas/preparação assíncrona podem usar payload criptografado temporário com limite de 24 horas; ver [contrato do chat](../operacao/chat-realtime.md).

Documentar a implementação escolhida para esse contexto, incluindo limpeza, recuperação após falha e limites. Não incluir corpos de mensagens em filas duráveis, tarefas falhas, logs ou backups por conveniência.

Se forem usados recursos persistidos de conversa na OpenAI, implementar sua limpeza. A API informa que excluir a conversa não exclui seus itens [F-02]. O descarte no SaaS também não constitui garantia de eliminação imediata de todas as cópias técnicas no provedor: a documentação distingue estado de aplicação e retenção para monitoramento [F-03].

Falha de limpeza remota deve ser observável e tratada sem reativar a sessão encerrada. O contexto antigo permanece indisponível para novas execuções, mesmo se uma remoção remota ainda estiver pendente.

## 7. Inatividade e expiração

### 7.1. Contagem

- Iniciar um prazo na entrada do bloco, inclusive quando ainda não chegou uma nova mensagem do lead naquela sessão.
- Cada nova mensagem elegível do lead, processada uma única vez, atualiza o prazo de inatividade.
- Respostas da IA, eventos de leitura/entrega, mensagens próprias e webhooks duplicados não reiniciam o prazo.
- Um timer antigo deve conferir o prazo vigente; não pode encerrar a sessão depois de uma interação que o tenha renovado.
- A expiração funciona no backend, independentemente de navegador aberto.

Os detalhes de relógio, recebimento e ordenação devem usar a estratégia consistente do executor existente.

### 7.2. Ação configurada

| Ação ao expirar                     | Resultado                                                                                                                           |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| **Encerrar o fluxo atual** — padrão | Marcar a sessão como expirada, descartar contexto e encerrar aquela execução, sem percorrer **Continuar**.                          |
| **Abrir atendimento humanizado**    | Marcar a sessão como expirada, descartar contexto e acionar o mesmo serviço de abertura humana da saída **Atendimento Humanizado**. |

Registrar a causa **inatividade**, distinguindo-a da conclusão solicitada pela IA. Não criar um conector adicional de timeout.

Resposta, tool e expiração precisam disputar o mesmo estado de sessão de forma atômica. Apenas uma transição final pode vencer. Uma mensagem recebida após o encerramento não ressuscita essa sessão; segue o processamento normal do sistema, podendo gerar uma nova entrada com contexto vazio se as regras a levarem ao IA novamente.

## 8. Recomendações de engenharia

Estas recomendações implementam as garantias descritas; nomes de serviços, campos e tabelas não são migrations aprovadas.

- Encapsular OpenAI em um adaptador/serviço, mantendo credenciais, chamadas e tratamento de respostas no backend.
- Reutilizar o executor, as filas e o serviço de abertura humana existentes; não criar um segundo sistema de atendimento.
- Persistir estado operacional da sessão e referências necessárias aos jobs; escolher o mecanismo de memória temporária conforme a política de conteúdo.
- Usar atualização condicional/transação para finalizar uma sessão, evitando duas saídas, dois avanços ou duas aberturas humanas.
- Serializar o processamento por sessão e manter ordem consistente para mensagens próximas.
- Antes de enviar ao WhatsApp, revalidar sessão, passo de execução, pausa e controle humano. Uma resposta pode ter sido gerada antes de a sessão ser encerrada.
- Revalidar jobs de timeout pelo identificador da sessão e pelo prazo atualizado.
- Separar falha de chamada OpenAI de falha de envio WhatsApp. Após um envio de resultado incerto, não repetir cegamente a mensagem.
- Registrar apenas metadados úteis ao diagnóstico; não registrar chave, prompt completo combinado com diálogo ou payload recebido do lead.

Estados internos possíveis: `active`, `completed`, `handed_off`, `expired`, `interrupted` e `failed`. São estados técnicos sugeridos, não novas saídas no editor.

## 9. Decisões adicionais da primeira versão

| Tema               | Regra aprovada em 13/10/2026                                                                                                                                                                                       |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Falhas OpenAI      | Após até três tentativas para rejeições transitórias, encaminhar ao atendimento humano. Chave/modelo inválidos, timeout ou resposta inválida também encaminham ao humano sem simular conclusão.                    |
| Início da conversa | Gerar abertura pelo prompt ao entrar no bloco; não usar a mensagem que disparou o fluxo nessa abertura.                                                                                                            |
| Modalidades        | Receber texto, imagem, áudio transcrito e PDF/DOCX/TXT; responder sempre em texto. Para vídeo, pedir texto ou outro formato e manter a sessão.                                                                     |
| Modelos            | Lista compatível: GPT-6 Luna, GPT-6.1 Sol e GPT-6 Astra; Sol é o padrão. Verificar acesso pela chave do workspace.                                                                                                 |
| Duração e consumo  | Inatividade padrão de 1 hora, configurável de 5 minutos a 7 dias. Limite de mensagens opcional, desligado por padrão; quando ativado, o administrador escolhe Continuar ou atendimento humano ao atingir o limite. |

A representação e a limpeza do contexto são escolhas técnicas a registrar no PR, obedecendo ao ciclo de vida aprovado. Não exigem uma nova decisão de produto para cada detalhe de armazenamento.

Falhas permanecem observáveis por metadados de sessão e fila, sem avançar como objetivo cumprido.

Bases de conhecimento externas, busca na web, gravação permanente de diálogos e resumo em notas permanecem fora deste ciclo. Ferramentas de mídia, tags e campos foram incorporadas em 14/10/2026 pela [evolução Assistente IA](bloco-assistente-ia.md), com instruções e permissões por recurso.

## 10. Critérios de aceite

| ID       | Cenário                                          | Resultado esperado                                                                                            |
| -------- | ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------- |
| AC-IA-01 | Criar/configurar bloco                           | Oferece prompt, modelo, prazo e ação de expiração; mostra somente **Continuar** e **Atendimento Humanizado**. |
| AC-IA-02 | Lead envia mensagens sucessivas                  | A sessão mantém contexto e o fluxo permanece no bloco até uma transição válida.                               |
| AC-IA-03 | IA solicita Continuar com conexão                | Conclui uma única vez, descarta contexto e segue o bloco conectado.                                           |
| AC-IA-04 | IA solicita Continuar sem conexão                | Conclui o bloco e encerra a execução atual.                                                                   |
| AC-IA-05 | IA solicita Atendimento Humanizado               | Abre atendimento pelo serviço existente; a IA não continua respondendo.                                       |
| AC-IA-06 | Mesmo contato retorna ao mesmo bloco             | Recebe sessão nova, sem recuperar o contexto anterior.                                                        |
| AC-IA-07 | Dois contatos/workspaces conversam               | Contextos, credenciais e transições permanecem isolados.                                                      |
| AC-IA-08 | IA apenas envia mensagem normal                  | Não aciona saída nem conclui o bloco por reconhecimento de frase.                                             |
| AC-IA-09 | Tool repetida ou resultados concorrentes         | Uma única transição vence; não duplica avanço nem abertura humana.                                            |
| AC-IA-10 | Nova mensagem chega antes de expirar             | Renova o prazo conforme a contagem; o timer antigo não encerra a sessão.                                      |
| AC-IA-11 | Evento duplicado ou mensagem da IA               | Não renova indevidamente a inatividade.                                                                       |
| AC-IA-12 | Expiração configurada para encerrar              | Fecha a sessão e a execução atual, sem acionar Continuar.                                                     |
| AC-IA-13 | Expiração configurada para humano                | Fecha a sessão e abre atendimento humano, com causa de inatividade.                                           |
| AC-IA-14 | Resposta/tool e timeout competem                 | Apenas uma conclusão ocorre; a outra operação reconhece o estado vigente.                                     |
| AC-IA-15 | Resposta OpenAI chega após saída/interrupção     | Não é enviada ao lead nem adicionada a uma sessão nova.                                                       |
| AC-IA-16 | Atendimento humano assume ou interrompe a sessão | A IA perde o controle e os envios pendentes são invalidados conforme o domínio existente.                     |
| AC-IA-17 | Publicado recebe edição em rascunho              | A sessão ativa mantém sua definição consistente; novas publicações seguem o versionamento existente.          |
| AC-IA-18 | Consultar configuração/logs/filas                | Não expõe a chave completa nem mantém histórico de mensagens por meio desses mecanismos.                      |
| AC-IA-19 | Sessão descartada com recurso remoto             | O contexto não é reutilizado; limpeza é executada ou falha fica observável para tratamento.                   |
| AC-IA-20 | API falha ou recebe mídia não prevista           | Segue a política definida antes da liberação; nunca simula sucesso ou conclusão do objetivo.                  |

## 11. Ordem de implementação da evolução

1. **Contrato e configurações:** tipo IA, dois resultados, campos do bloco e integração OpenAI do workspace; fechar pendências que afetem a primeira versão.
2. **Sessão e adaptador:** contexto por entrada, chamada OpenAI e validação de modelo/tools, com estado operacional isolado.
3. **Transições:** tool interna, Continuar, encerramento sem conexão e abertura humana idempotente.
4. **Expiração e descarte:** prazo renovável, ações configuradas, disputa atômica e invalidação de respostas antigas.
5. **Homologação:** executar os cenários de aceite e verificar a operação junto ao chat e ao executor reais.

## 12. Incorporação na documentação do repositório

- Adicionar este documento ao índice de `docs/README.md`.
- Acrescentar as decisões IA-01 a IA-12 ao registro de decisões, preservando a distinção entre aprovado e pendente.
- Atualizar a biblioteca/tabela de blocos do editor: IA com uma entrada e duas saídas, mantendo as regras particulares de encaminhamento e expiração descritas aqui.
- Sincronizar a referência ao gatilho humano e ao contexto temporário com os documentos responsáveis.
- Manter como implementado somente o que tiver PR/código e verificação correspondentes.

Este arquivo é uma especificação complementar. Os anexos originais são registros históricos; os documentos vigentes do editor, chat e integrações descrevem o estado consolidado.

## 13. Fontes técnicas oficiais

Consultadas em 12/10/2026; não equivalem a teste da integração na conta do cliente.

- **F-01 — [Function calling](https://developers.openai.com/api/docs/guides/function-calling):** chamada de ferramenta pelo modelo e execução pela aplicação.
- **F-02 — [Delete a conversation](https://developers.openai.com/api/reference/resources/conversations/methods/delete):** exclusão de conversa e distinção em relação aos seus itens.
- **F-03 — [Data controls](https://developers.openai.com/api/docs/guides/your-data):** estado de aplicação e controles de retenção no provedor.

## 14. Histórico

- **v0.2 — 13/10/2026:** contrato de produto definido: abertura gerada pelo prompt sem usar a mensagem que disparou o fluxo; respostas em texto; texto, imagem, áudio transcrito e PDF/DOCX/TXT recebidos; vídeo recebe pedido de outro formato. Lista de modelos Luna, Sol e Astra, Sol padrão. Inatividade padrão de 1 hora, de 5 minutos a 7 dias. Limite de mensagens opcional, desligado por padrão; ao atingi-lo, escolher Continuar ou atendimento humano. Falha da OpenAI encaminha ao humano. Implementação local adiciona integração protegida por workspace, nó IA, sessão, jobs e limpeza remota; falta homologação operacional.

- **v0.1 — 12/10/2026:** consolidadas instruções no bloco, conclusão por tool, duas saídas, contexto restrito à sessão, chave do cliente, seleção de modelo e inatividade com ação configurável; registrados requisitos de concorrência, descarte e pendências para a liberação.

## Evolução Assistente IA — 14/10/2026

O mesmo tipo `ai` agora aceita mídias, tags e campos opcionais, com validação no backend e retorno de ferramentas ao modelo. Ver [contrato e homologação](bloco-assistente-ia.md). Mantém chave, contexto, saídas, inatividade, modalidades recebidas e políticas anteriores.

## Prompt com campos do contato e continuidade — 15/10/2026

O prompt aceita ContactTemplate v1, resolvido antes de cada chamada com snapshot atual. Valores seguem separados das instruções, em entrada de dados não confiáveis; não ampliam ferramentas/permissões. Continua proibido revelar dados pessoais do cadastro, mesmo recebidos por chips. Recursos e suas instruções não aceitam chips. Prompt personalizado inválido e recusa do envio da resposta seguem **Continuar**, com limpeza remota; falhas próprias da OpenAI mantêm a política anterior. Transporte incerto persiste somente metadados e usa conciliação recuperável, sem gerar/enviar novamente. Ao esgotar, registra resultado desconhecido e continua. Ver [contrato e homologação pendente](campos-do-contato-fluxos.md).
