# Bloco Assistente IA — Especificação funcional e técnica

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Projeto:** chAIt — SaaS de atendimento e automações (Laravel)  
> **Documento independente:** `docs/modulos/bloco-assistente-ia.md`  
> **Versão:** 0.3  
> **Data:** 14/10/2026  
> **Estado:** implementado e homologado com APIs reais OpenAI/UAZAPI e dados sintéticos; recebimento de mensagens WhatsApp nesta rodada usa adaptador sintético  
> **Relação:** evolução implementada de `editor-de-fluxos.md` e `chat-multiatendimento.md`, sem modificar esses documentos.

## 1. Objetivo

Permitir que um **Assistente IA** converse com um lead enquanto a execução estiver nesse bloco do fluxo, seguindo instruções do administrador, com capacidade de:

- Responder ao lead pelo WhatsApp.
- Enviar **imagem, vídeo, áudio e documento** previamente cadastrados no bloco.
- **Adicionar tags** autorizadas ao contato.
- **Preencher ou atualizar campos personalizados** autorizados do contato.
- Concluir sua participação e prosseguir no fluxo ou encaminhar a conversa ao atendimento humano.

O assistente não recebe acesso irrestrito para **alterar** o CRM. Ler informações e modificá-las são permissões distintas.

**Fora deste ciclo de decisões:** geração e manutenção de notas/resumos pela IA. A funcionalidade de notas permanece para discussão futura; nenhuma regra de criação automática de notas é aprovada por este documento.

## 2. Contexto do produto e integração

- **Frontend/editor:** Vue 3 + Inertia.js e Vue Flow, segundo a arquitetura aprovada do projeto.
- **Motor:** Laravel, com Redis/Horizon para processamento assíncrono; o modelo de IA não executa mutações diretamente no banco.
- **Canal inicial:** WhatsApp por UAZAPI; um número por workspace na primeira versão.
- **Isolamento:** dados, credenciais, arquivos, leads e recursos acessados por um bloco pertencem ao seu workspace.
- **Base existente:** reutiliza o bloco `ai`, executor, chat e multiatendimento. Listas novas são opcionais e ausentes equivalem a permissões de escrita/envio vazias.
- **Histórico do WhatsApp:** permanece na UAZAPI, conforme decisão existente de não guardar o corpo/histórico das mensagens no banco do SaaS. Isso não impede valores de campos e registros operacionais autorizados. No modo de tempo real, prévias e conteúdo criptografado temporário obedecem ao [contrato do chat](../operacao/chat-realtime.md), sem cópia permanente completa do histórico.

## 3. Configuração básica do bloco — decisões anteriores mantidas

| Elemento                    | Comportamento                                                                                                                                          |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Instruções da IA**        | Texto/prompt configurado dentro de cada bloco.                                                                                                         |
| **Credencial OpenAI**       | Fornecida pelo cliente, que arca com o uso; configuração protegida no contexto do workspace, fora do texto do bloco.                                   |
| **Modelo**                  | Administrador escolhe o modelo OpenAI utilizado pelo bloco.                                                                                            |
| **Outras opções do modelo** | Expor somente parâmetros com necessidade real; opções exatas ainda não definidas.                                                                      |
| **Contexto da conversa**    | Válido somente enquanto o lead estiver **naquela execução daquele bloco**.                                                                             |
| **Saída e retorno**         | Ao sair, a sessão/contexto do assistente deixa de servir à execução. Se o contato entrar novamente, inicia uma nova sessão, sem reutilizar a anterior. |

### 3.1. Saídas do bloco

O bloco possui **duas saídas**, não três:

1. **Continuar:** o assistente concluiu a tarefa do bloco e o executor segue o conector `Continuar`. Caso não haja próximo bloco conectado e o fluxo permitir término implícito, a execução termina normalmente; o comportamento geral de conectores finais deve seguir a decisão do editor de fluxos.
2. **Atendimento Humanizado:** solicita a abertura do atendimento humano pela regra já adotada pelo módulo de multiatendimento. O robô deixa de responder e a conversa fica disponível na fila de não atribuídas, sinalizada como aguardando atendimento, até ser assumida por alguém autorizado.

**Não existe saída independente `Encerrar`.** A continuação do fluxo é determinada pelo conector `Continuar` e pelos blocos seguintes. Os critérios são definidos no prompt do bloco, sem exigir um campo separado para conclusão.

### 3.2. Prioridade do atendimento humano

Se um atendente assumir a conversa enquanto a IA estiver ativa, ou se ocorrer uma transição válida para atendimento humano, a automação deve deixar de responder imediatamente, e os envios pendentes devem ser invalidados. A finalização posterior do atendimento humano **não retoma a execução anterior** do bloco IA. Novas mensagens passam pelas regras de disparo pertinentes ao estado atual, sem reproduzir mensagens recebidas durante o atendimento humano.

## 4. Configuração de mídias — aprovado

O administrador poderá cadastrar **múltiplos arquivos** dentro de cada bloco IA. Cada item terá:

| Campo               | Descrição                                                    |
| ------------------- | ------------------------------------------------------------ |
| Arquivo             | Upload de imagem, vídeo, áudio ou documento.                 |
| Instruções de envio | Texto explicando **quando** a IA deve enviar aquele arquivo. |

**Exemplo:**

- Arquivo: `catalogo.pdf`.
- Instrução: “Enviar quando o cliente pedir o catálogo, solicitar a relação de produtos ou demonstrar interesse em conhecer os modelos disponíveis.”

### 4.1. Limite de reenvio — aprovado

1. Cada arquivo cadastrado admite **uma única tentativa de envio por sessão do bloco**.
2. **Não há reenvio**, mesmo quando o cliente solicitar novamente. Uma falha ou resultado incerto após a chamada ao provedor consome a tentativa.
3. Uma **nova entrada no bloco** cria sessão e controle novos. Arquivo inválido ou indisponível é bloqueado antes da chamada e não consome tentativa.
4. O **Laravel** controla o estado do envio e valida a autorização, sem depender apenas da memória ou da decisão do modelo.
5. Envios agendados ou atrasados não podem ocorrer depois que a execução deixar de estar ativa ou quando houver atendimento humano prioritário.

A decisão da IA é **quando solicitar** o envio, conforme as instruções; o envio real passa por um serviço do backend, que valida arquivo, workspace, estado da execução, limite de tentativa e integridade da operação.

### 4.2. Limites e tratamento implementados

- Arquivos devem ser guardados em armazenamento privado ou com URLs temporárias e vinculados ao workspace.
- Validar tipos MIME reais, extensão, tamanho e disponibilidade antes da publicação e novamente antes do envio.
- Confirmar limites e compatibilidade da **versão efetivamente contratada da UAZAPI**; não pressupor que áudio, vídeo, documentos e imagens têm formatos e limites idênticos.
- O administrador deve ver se o arquivo está válido ou indisponível.
- Erros e respostas incertas do provedor não podem desencadear reenvios cegos, sujeitos a duplicação.
- Cada tentativa é reservada atomicamente por sessão/arquivo antes do envio. A IA não pode contornar o limite usando outro `call_id`.

**Contrato aprovado:** até 20 arquivos por bloco, até 20 MiB por arquivo; imagem JPEG/PNG/WebP/GIF, vídeo MP4, áudio MP3/OGG e documento PDF/DOC/DOCX/XLS/XLSX/TXT. Sem legenda; envios sequenciais na ordem solicitada pela IA. Falha, arquivo indisponível ou resultado incerto mantém a conversa em texto. Consultar o provedor pelo rastreamento sem filtrar o número, que pode ser normalizado pelo WhatsApp; não reenviar. Os quatro tipos foram entregues no teste de 14/10/2026. Isto não valida todas as extensões e tamanhos máximos na instância.

## 5. Configuração de tags — aprovado

O administrador escolhe as **tags que o assistente está autorizado a adicionar** e fornece uma instrução **individual por tag** descrevendo quando aplicá-la.

| Campo          | Descrição                                                      |
| -------------- | -------------------------------------------------------------- |
| Tag            | Selecionada dentre as tags existentes no workspace.            |
| Quando aplicar | Instrução textual do administrador orientando a decisão da IA. |

**Exemplo:** tag `Interessado`; instrução: “Adicionar quando o cliente demonstrar interesse concreto em um produto ou serviço.”

### 5.1. Restrições obrigatórias

- O assistente **somente pode adicionar** tags listadas/autorizadas no bloco.
- O assistente **não pode remover** tags, inclusive as que ele adicionou anteriormente.
- Se o lead já tiver a tag, a aplicação é uma operação **idempotente**, sem criar duplicidade.
- A IA não pode criar novas tags ou alterar o cadastro/definição das tags por essa funcionalidade.
- As tags podem ser adicionadas **durante a conversa**, sem esperar a saída do bloco.
- Tags aplicadas permanecem registradas caso a conversa termine ou seja encaminhada ao atendimento humano.

**Ponto de atenção:** uma tag pode deixar de refletir a situação atual depois de mudanças na intenção do cliente. Como a remoção pela IA não está autorizada, eventuais ajustes dependerão de outro mecanismo legítimo do produto (por exemplo, ações humanas ou outro bloco autorizado).

## 6. Configuração de campos personalizados — aprovado

O administrador escolhe **cada campo personalizado** que poderá ser alterado e escreve uma instrução individual orientando **quando** e **qual valor** a IA deve preencher.

| Campo                                 | Descrição                                           |
| ------------------------------------- | --------------------------------------------------- |
| Campo personalizado                   | Campo existente no workspace, selecionado no bloco. |
| Instruções                            | Quando alterar e qual informação registrar.         |
| Permitir sobrescrever valor existente | Checkbox independente **por campo**.                |

**Exemplo:** campo `Curso de interesse`; instrução: “Registrar o curso que o cliente demonstrar interesse em fazer.”

### 6.1. Regras de escrita

1. A IA **só altera campos selecionados/autorizados** no bloco.
2. Se o campo estiver vazio, pode preenchê-lo conforme as instruções.
3. Se o campo já estiver preenchido, **só poderá substituir o valor** se o checkbox `Permitir sobrescrever valor existente` daquele campo estiver ativado.
4. O checkbox inicia **desmarcado por padrão**, conforme o plano aprovado.
5. A IA pode atualizar dados **durante a conversa**, quando identificar informação suficientemente confiável.
6. As alterações já feitas permanecem válidas após saída do bloco ou transferência para atendimento humano.
7. O backend revalida campo, workspace, autorização e estado atual antes de qualquer gravação; não confiar em identificadores apresentados pelo modelo.

### 6.2. Interpretação contextual — aprovado

A IA **não precisa** receber do cliente a frase exata correspondente ao nome do campo. Pode inferir informações do contexto da conversa **quando houver evidência suficiente**, mas:

- Não pode inventar fatos ou apresentar hipóteses como certezas.
- Não deve preencher o campo se os dados forem insuficientes ou ambíguos.
- Pode perguntar ao cliente para esclarecer uma informação necessária antes de gravá-la.
- Deve respeitar instruções do administrador, permissões e regra de sobrescrita mesmo quando o valor inferido pareça plausível.

**Exemplo válido:** “Procuro um curso para conseguir meu primeiro emprego” pode justificar `Objetivo profissional = Primeiro emprego`.  
**Exemplo inválido:** supor a idade ou renda do cliente apenas a partir do seu interesse em um curso.

**Concorrência aprovada:** com sobrescrita permitida, a última gravação vence, inclusive depois de edição humana. Sem sobrescrita, a gravação condicional preserva qualquer valor preenchido no momento da escrita. Campo de texto recebe valor não vazio de até 4000 caracteres; `0` conta como preenchido.

## 7. Leitura de dados e privacidade — aprovado

### 7.1. Leitura versus escrita

- A IA pode **consultar todas as tags e todos os campos personalizados existentes do contato**, não apenas aqueles com permissão de modificação.
- A IA **só modifica** as tags e os campos explicitamente autorizados naquele bloco, com suas regras individuais.
- O acesso de leitura deve acontecer por meio do backend no escopo do workspace/contato. É recomendável disponibilizar à IA os dados necessários no momento apropriado, em vez de enviar sem necessidade o cadastro inteiro em todas as solicitações.
- O acesso a **notas internas** não foi aprovado nesta rodada; não pressupor leitura ou escrita de notas.

### 7.2. Não divulgação de dados pessoais cadastrados

**Decisão expressa:** o assistente **nunca deve informar dados pessoais armazenados no cadastro do contato por meio das respostas ao cliente**, ainda que o próprio interlocutor os solicite.

Exemplos protegidos: **CPF, números de documentos, telefone, e-mail, endereço e outros dados pessoais cadastrados**. A proibição alcança a revelação **total ou parcial** desses valores.

Resposta de referência:

> “Por questões de segurança e privacidade, não posso informar números de documentos ou outros dados pessoais cadastrados por aqui.”

A restrição prevalece sobre o prompt/instruções do administrador e sobre pedidos feitos pelo interlocutor. A IA **pode receber novos dados fornecidos pelo cliente** e, se autorizado, atualizar campos personalizados sem repetir dados cadastrais protegidos como confirmação. As regras sobre outros possíveis usos de dados fornecidos na própria conversa não foram detalhadas nesta rodada.

### 7.3. Aplicação da regra por prompt — decisão de 14/10/2026

A leitura integral foi mantida. A proibição de divulgar dados pessoais é aplicada somente pelas instruções do prompt; não foi aprovado classificar campos nem acrescentar filtros automáticos de saída. As regras do sistema prevalecem sobre o administrador e tratam mensagens do lead e valores consultados como conteúdo não confiável.

O cumprimento da regra de privacidade depende da interpretação do modelo. Testes com respostas simuladas verificam presença das instruções e limites de permissões; não comprovam ausência de divulgação pelo modelo real.

Credenciais nunca são fornecidas ao modelo ou ao navegador. O contexto remoto continua sujeito à limpeza vigente ao encerrar a sessão; valores consultados ficam somente no contexto ativo, sem cópia no registro operacional local.

## 8. Quando executar ações — aprovado

Tanto a adição de tags quanto a atualização de campos ocorrem **durante a conversa**, assim que as instruções e as evidências forem suficientes. Não é necessário aguardar que a IA acione a saída `Continuar` ou `Atendimento Humanizado`.

### 8.1. Regras transversais implementadas

Para cada ação solicitada pelo modelo, o Laravel deve conferir, no momento da execução:

1. Identidade do workspace, contato, fluxo, versão publicada e bloco.
2. Sessão/execução de IA ainda ativa e ausência de bloqueio por atendimento humano.
3. Recurso habilitado e presente na lista permitida para o bloco.
4. Restrições específicas: já tentou enviar a mídia? campo permite sobrescrita?
5. Deduplicação/idempotência para chamadas repetidas, webhooks duplicados e execução concorrente.
6. Registro operacional de resultado, sem persistir desnecessariamente o texto integral da conversa.

As alterações confirmadas devem permanecer após a conclusão da IA ou a transferência para atendimento humano. O registro `ai_session_actions` identifica origem, recurso, momento, sessão e resultado. Ele não registra os valores pessoais nem os argumentos textuais da ferramenta.

## 9. Contrato técnico implementado

**Separação de responsabilidades:**

- **Modelo OpenAI:** interpreta mensagem/contexto, conversa com o lead e **solicita ações estruturadas** com base nas instruções.
- **Serviço Laravel:** decide se a ação é válida, persiste alterações autorizadas e chama o adaptador de WhatsApp para envios.
- **Executor de fluxos:** mantém execução ativa, saídas válidas, isolamento e invalidação de operações quando há interrupção/handoff.

Function tools internas, com schemas estritos e `parallel_tool_calls=false`:

| Tool                               | Contrato                                                                                                   |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `consultar_contato()`              | Todas as tags e campos personalizados do contato do workspace, incluindo valores não editáveis; sem notas. |
| `enviar_midia(asset_id)`           | Arquivo autorizado da mesma versão/fluxo/workspace, válido, uma tentativa por sessão e envio sem legenda.  |
| `adicionar_tag(tag_id)`            | Tag autorizada do workspace; nenhuma remoção ou criação.                                                   |
| `preencher_campo(field_id, value)` | Campo de texto autorizado, valor válido e regra individual de sobrescrita.                                 |
| `concluir_bloco_ia(saida)`         | `continuar` ou `atendimento_humanizado`; transição final única.                                            |

O backend deriva workspace, contato e versão da sessão. Resultados intermediários retornam em `function_call_output` pelo `call_id` antes de uma nova resposta do modelo. A tool terminal encerra a sessão e invalida seu contexto.

O grafo v2 recebe listas opcionais `ai_media`, `ai_tags` e `ai_fields`; cada item contém ID do recurso e `instruction`, com `allow_overwrite` por campo. Instruções individuais têm até 2000 caracteres; até 50 permissões de tags e 50 campos. Rascunhos podem conter itens incompletos; publicação exige recursos válidos e instruções preenchidas. O mesmo recurso pode aparecer em blocos diferentes, mas não se repetir dentro do mesmo bloco.

`ai_session_actions` registra apenas metadados e deduplica por sessão/`call_id`. `ai_session_media_attempts` reserva tentativas por sessão/arquivo, inclusive se o resultado for incerto. Valores e argumentos textuais não são gravados nesses registros. Alterações confirmadas de CRM permanecem depois da saída; recursos removidos ou inválidos bloqueiam somente a ação.

Cada turno tem limite de **20 chamadas de ferramentas e 150 segundos**. Chamadas externas compartilham o prazo restante, incluindo leitura de mídia, transcrição, modelo e WhatsApp. Esgotamento abre atendimento humano com causa operacional, respeitando o job de 180 segundos. Inatividade, limite de mensagens, abertura pelo prompt e limpeza mantêm o contrato do [Bloco IA](bloco-ia.md).

### 9.1. Contexto temporário

- Manter contexto só pelo tempo necessário para sustentar as interações **dessa execução**.
- Não reutilizar a mesma sessão/thread ao reentrar no bloco.
- Encerrar/revogar o contexto operacional e programar a limpeza de eventuais estruturas temporárias ao sair/interromper.
- A implementação usa contexto remoto por sessão e o job `CleanupAiSession`; `OpenAiClient::cleanup` remove itens em lotes e depois a conversa, tratando ausência remota como limpeza concluída. Falhas mantêm a limpeza observável e não reativam a sessão. Isso não comprova exclusão instantânea de todas as cópias técnicas do provedor.
- Não criar uma cópia permanente do histórico WhatsApp no Laravel para facilitar a IA sem alterar a política de dados existente.

## 10. Interface de configuração implementada

No painel esquerdo do bloco IA no editor Vue Flow, organizar as opções em grupos:

1. **Assistente:** instruções, modelo e configuração disponível ao administrador; credencial OpenAI gerenciada com segurança no workspace.
2. **Saídas:** critérios no prompt; os dois conectores existentes permanecem visíveis.
3. **Mídias:** itens adicionáveis com upload e instrução individual.
4. **Tags:** lista de tags escolhidas, cada uma com instrução “Quando aplicar”.
5. **Campos personalizados:** seleção, instrução por campo e checkbox individual “Permitir sobrescrever”.

**Não incluir nesta versão** controles de notas automáticas. O canvas conserva o bloco compacto e as duas saídas visíveis; o formulário detalhado fica no painel de edição.

O painel foi implementado no editor existente e verificado no navegador, com salvamento, reabertura e viewport mobile. Arquivos ausentes são sinalizados; a publicação e o envio revalidam conteúdo, MIME, extensão e tamanho.

## 11. Cenários mínimos de aceite funcional

| ID    | Cenário                                                              | Resultado esperado                                                                                                 |
| ----- | -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| IA-01 | IA envia mídia cadastrada pela primeira vez                          | Envio permitido quando a instrução é atendida e o backend valida.                                                  |
| IA-02 | IA tenta reenviar a mesma mídia sem novo pedido do cliente           | Envio bloqueado.                                                                                                   |
| IA-03 | Cliente solicita novamente mídia já enviada                          | Reenvio bloqueado naquela sessão.                                                                                  |
| IA-04 | Lead entra no mesmo bloco em outra execução                          | Controle de envio reiniciado e novo contexto de IA.                                                                |
| IA-05 | IA tenta enviar mídia não cadastrada no bloco                        | Operação negada.                                                                                                   |
| IA-06 | IA adiciona tag autorizada que o lead ainda não possui               | Tag vinculada ao lead correto.                                                                                     |
| IA-07 | IA tenta aplicar tag não autorizada ou remover tag                   | Operação negada.                                                                                                   |
| IA-08 | IA tenta adicionar tag já existente                                  | Nenhuma associação duplicada criada.                                                                               |
| IA-09 | IA preenche campo autorizado vazio                                   | Valor registrado se houver evidência suficiente.                                                                   |
| IA-10 | Campo já tem valor e sobrescrita está desmarcada                     | Valor atual preservado.                                                                                            |
| IA-11 | Campo já tem valor e sobrescrita está marcada                        | Atualização permitida, validada pelo backend.                                                                      |
| IA-12 | IA tenta editar campo não selecionado no bloco                       | Operação negada.                                                                                                   |
| IA-13 | Informações na conversa são ambíguas                                 | IA não inventa valor; pode perguntar ao lead.                                                                      |
| IA-14 | Cliente pede CPF/e-mail/telefone ou parte de dado pessoal cadastrado | Prompt instrui a não revelar; o comportamento real precisa de avaliação com o modelo, sem garantia determinística. |
| IA-15 | IA aplica uma tag ou atualiza campo e depois solicita handoff        | Alteração preservada; automação interrompida conforme a regra humana.                                              |
| IA-16 | Job antigo tenta enviar mídia depois de atendimento humano iniciado  | Envio bloqueado.                                                                                                   |
| IA-17 | IA solicita `Continuar`                                              | Executor usa somente a saída correspondente e encerra a sessão IA.                                                 |
| IA-18 | IA solicita atendimento humano                                       | Executor usa somente a saída humana; não executa também `Continuar`.                                               |
| IA-19 | Contato de outro workspace é usado em chamada de ação                | Rejeição por isolamento/autorização.                                                                               |
| IA-20 | Dois eventos/processos tentam a mesma ação simultaneamente           | Idempotência e consistência do estado são preservadas.                                                             |

## 12. Validação e pendências operacionais

- Testes automatizados cobrem consulta, permissões, isolamento, gravação condicional, deduplicação e reutilização indevida de `call_id`, tentativas de mídia, resultados incertos, recursos removidos, expiração, interrupção humana, ferramentas terminais, limites de turno e reagendamento quando a sessão está ocupada.
- Verificação final: 244 testes PHP (1360 asserções), incluindo 60 cenários do bloco IA; dois testes de navegador para configuração/persistência e compatibilidade do editor. Tipos, lint, build e formatação dos arquivos alterados passaram. Upload no navegador é simulado; os quatro envios de transporte usam a instância real.
- **14/10/2026:** imagem PNG, vídeo MP4, áudio MP3 e documento TXT enviados uma vez no workspace atual ao número de teste indicado pelo usuário; os quatro retornaram `Delivered` na consulta por `track_id`. Evidência em `artifacts/assistente-ia-homologacao/resultado.json`, sem corpo de mensagem ou credenciais.
- Após cadastro da chave do workspace, os três modelos responderam e seus contextos foram removidos. O teste integrado com Sol passou em 20 verificações: tools de CRM, quatro mídias, bloqueio de reenvio, memória, pedidos de dados pessoais completos/parciais/manipulados, atualização e preservação de campos, Continuar, nova sessão sem contexto anterior, Atendimento Humanizado e limpeza remota. Foram usadas entradas sintéticas e uma base em memória; as APIs OpenAI e os envios WhatsApp são reais. A base principal e a chave cadastrada foram preservadas. Evidências em `openai-smoke.json` e `openai-runtime.json`, na mesma pasta de homologação.
- Na API OpenAI real, leitura de imagem PNG, PDF, DOCX e TXT com marcadores sintéticos e transcrição de fala em português passaram. Os quatro contextos de leitura foram removidos. Evidência: `openai-inputs.json`. Isto testa as entradas já normalizadas para OpenAI, não uma mensagem de mídia enviada pelo lead pela UAZAPI.
- Arquivos próximos aos limites máximos, outros formatos Office e respostas livres de usuários reais continuam fora desta amostra. A política de privacidade por prompt foi respeitada nos três casos testados; esses resultados não garantem proteção em todos os diálogos.

## 13. Registro das decisões desta rodada

| ID     | Decisão                                                                                                               | Estado                                     |
| ------ | --------------------------------------------------------------------------------------------------------------------- | ------------------------------------------ |
| IA-D01 | Cadastrar mídias por upload com instrução individual de quando enviar                                                 | **Aprovado**                               |
| IA-D02 | Uma tentativa de envio por mídia/sessão, inclusive em falha; não reenviar mesmo com novo pedido                       | **Aprovado**                               |
| IA-D03 | Permitir somente **adicionar** tags selecionadas no bloco, com instrução por tag                                      | **Aprovado**                               |
| IA-D04 | Selecionar campos editáveis e configurar sobrescrita **individualmente** por campo                                    | **Aprovado**                               |
| IA-D05 | Permitir interpretação contextual com evidência suficiente, sem inventar informações                                  | **Aprovado**                               |
| IA-D06 | IA pode **ler todas** as tags e os campos personalizados do contato; alterar somente os autorizados                   | **Aprovado**                               |
| IA-D07 | Tags e campos podem ser modificados **durante a conversa**, sem esperar a saída do bloco                              | **Aprovado**                               |
| IA-D08 | Prompt proíbe divulgar dados pessoais cadastrados, inclusive parcialmente; leitura integral e cumprimento pelo modelo | **Aprovado**                               |
| IA-D09 | Não definir agora a lógica de notas automáticas da IA                                                                 | **Adiado deliberadamente**                 |
| IA-D10 | Duas saídas: `Continuar` e `Atendimento Humanizado`; sem saída `Encerrar`                                             | **Aprovado em discussão anterior**         |
| IA-D11 | Prompt no bloco, modelo por bloco, chave OpenAI custeada pelo cliente e sessão limitada à execução                    | **Direção aprovada em discussão anterior** |

## 14. Histórico

- **v0.3 — 14/10/2026:** chave cadastrada pelo usuário e homologação real concluída. Três modelos, 20 verificações de runtime com Sol e cinco modalidades de entrada passaram; contextos removidos. CRM isolado em memória e entradas sintéticas, com envios reais ao número autorizado.

- **v0.2 — 14/10/2026:** plano aprovado e implementado como evolução do bloco existente. Uma tentativa por mídia, sem reenvio; 20 arquivos de 20 MiB, sem legendas; falha de mídia mantém texto; última gravação vence quando autorizada. Privacidade aplicada pelo prompt. Formulário, tools, registros operacionais e limites de turno implementados; quatro tipos entregues na instância real. Conversa OpenAI pendente de chave.

- **v0.1 — 13/10/2026:** documento independente criado para consolidar o bloco Assistente IA e as decisões sobre mídias, tags, campos personalizados, leitura de dados e privacidade; notas automáticas excluídas da definição atual. O documento de editor de fluxos anterior ainda lista IA fora do seu escopo original e **não foi alterado** por esta criação.
