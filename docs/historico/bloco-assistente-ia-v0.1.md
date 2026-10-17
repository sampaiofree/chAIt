> **Arquivo histórico v0.1.** As decisões de 14/10/2026 substituem regras desta versão, inclusive reenvio e proteção de saída. Consulte a [especificação atual](../modulos/bloco-assistente-ia.md).

# Bloco Assistente IA — Especificação funcional e técnica

> **Projeto:** chAIt — SaaS de atendimento e automações (Laravel)  
> **Documento independente:** `docs/modulos/bloco-assistente-ia.md`  
> **Versão:** 0.1  
> **Data:** 13/10/2026  
> **Estado:** briefing consolidado; **não representa implementação concluída**  
> **Relação:** complemento futuro de `editor-de-fluxos.md` e `chat-multiatendimento.md`, sem modificar esses documentos.

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
- **Prioridade de produto:** chat e multiatendimento continuam antecedendo a implementação de fluxos/IA. Este é um documento de planejamento, não uma autorização implícita para implementar antes dessa etapa.
- **Histórico do WhatsApp:** permanece na UAZAPI, conforme decisão existente de não guardar o corpo/histórico das mensagens no banco do SaaS. Isso não impede que valores de campos personalizados e registros operacionais autorizados sejam persistidos.

## 3. Configuração básica do bloco — decisões anteriores mantidas

| Elemento | Comportamento |
|---|---|
| **Instruções da IA** | Texto/prompt configurado dentro de cada bloco. |
| **Credencial OpenAI** | Fornecida pelo cliente, que arca com o uso; configuração protegida no contexto do workspace, fora do texto do bloco. |
| **Modelo** | Administrador escolhe o modelo OpenAI utilizado pelo bloco. |
| **Outras opções do modelo** | Expor somente parâmetros com necessidade real; opções exatas ainda não definidas. |
| **Contexto da conversa** | Válido somente enquanto o lead estiver **naquela execução daquele bloco**. |
| **Saída e retorno** | Ao sair, a sessão/contexto do assistente deixa de servir à execução. Se o contato entrar novamente, inicia uma nova sessão, sem reutilizar a anterior. |

### 3.1. Saídas do bloco

O bloco possui **duas saídas**, não três:

1. **Continuar:** o assistente concluiu a tarefa do bloco e o executor segue o conector `Continuar`. Caso não haja próximo bloco conectado e o fluxo permitir término implícito, a execução termina normalmente; o comportamento geral de conectores finais deve seguir a decisão do editor de fluxos.
2. **Atendimento Humanizado:** solicita a abertura do atendimento humano pela regra já adotada pelo módulo de multiatendimento. O robô deixa de responder e a conversa fica disponível na fila de não atribuídas, sinalizada como aguardando atendimento, até ser assumida por alguém autorizado.

**Não existe saída independente `Encerrar`.** A continuação do fluxo é determinada pelo conector `Continuar` e pelos blocos seguintes. Os critérios/instruções pelos quais o assistente decide usar cada saída são configuráveis no bloco; a interface e o formato final dessa configuração ainda exigem detalhamento.

### 3.2. Prioridade do atendimento humano

Se um atendente assumir a conversa enquanto a IA estiver ativa, ou se ocorrer uma transição válida para atendimento humano, a automação deve deixar de responder imediatamente, e os envios pendentes devem ser invalidados. A finalização posterior do atendimento humano **não retoma a execução anterior** do bloco IA. Novas mensagens passam pelas regras de disparo pertinentes ao estado atual, sem reproduzir mensagens recebidas durante o atendimento humano.

## 4. Configuração de mídias — aprovado

O administrador poderá cadastrar **múltiplos arquivos** dentro de cada bloco IA. Cada item terá:

| Campo | Descrição |
|---|---|
| Arquivo | Upload de imagem, vídeo, áudio ou documento. |
| Instruções de envio | Texto explicando **quando** a IA deve enviar aquele arquivo. |

**Exemplo:**

- Arquivo: `catalogo.pdf`.
- Instrução: “Enviar quando o cliente pedir o catálogo, solicitar a relação de produtos ou demonstrar interesse em conhecer os modelos disponíveis.”

### 4.1. Limite de reenvio — aprovado

1. Cada arquivo cadastrado poderá ser enviado **automaticamente uma única vez por execução do bloco**.
2. Um **novo envio do mesmo arquivo** será permitido se o cliente **solicitar novamente**.
3. Se o lead entrar no bloco em uma **nova execução**, o controle de envio daquele bloco será reiniciado.
4. O **Laravel** controla o estado do envio e valida a autorização, sem depender apenas da memória ou da decisão do modelo.
5. Envios agendados ou atrasados não podem ocorrer depois que a execução deixar de estar ativa ou quando houver atendimento humano prioritário.

A decisão da IA é **quando solicitar** o envio, conforme as instruções; o envio real passa por um serviço do backend, que valida arquivo, workspace, estado da execução, motivo do reenvio e integridade da operação.

### 4.2. Limitações e tratamento — recomendações técnicas

- Arquivos devem ser guardados em armazenamento privado ou com URLs temporárias e vinculados ao workspace.
- Validar tipos MIME reais, extensão, tamanho e disponibilidade antes da publicação e novamente antes do envio.
- Confirmar limites e compatibilidade da **versão efetivamente contratada da UAZAPI**; não pressupor que áudio, vídeo, documentos e imagens têm formatos e limites idênticos.
- O administrador deve ver se o arquivo está válido ou indisponível.
- Erros e respostas incertas do provedor não podem desencadear reenvios cegos, sujeitos a duplicação.
- Para reenvio excepcional, exigir que exista um novo pedido do cliente no contexto da execução; o modelo não pode contornar livremente a regra de uma vez.

**Ainda sem decisão de produto:** limite de arquivos por bloco, tamanho máximo por mídia, legendas, ordenação de múltiplas mídias solicitadas simultaneamente e política específica de falha de envio.

## 5. Configuração de tags — aprovado

O administrador escolhe as **tags que o assistente está autorizado a adicionar** e fornece uma instrução **individual por tag** descrevendo quando aplicá-la.

| Campo | Descrição |
|---|---|
| Tag | Selecionada dentre as tags existentes no workspace. |
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

| Campo | Descrição |
|---|---|
| Campo personalizado | Campo existente no workspace, selecionado no bloco. |
| Instruções | Quando alterar e qual informação registrar. |
| Permitir sobrescrever valor existente | Checkbox independente **por campo**. |

**Exemplo:** campo `Curso de interesse`; instrução: “Registrar o curso que o cliente demonstrar interesse em fazer.”

### 6.1. Regras de escrita

1. A IA **só altera campos selecionados/autorizados** no bloco.
2. Se o campo estiver vazio, pode preenchê-lo conforme as instruções.
3. Se o campo já estiver preenchido, **só poderá substituir o valor** se o checkbox `Permitir sobrescrever valor existente` daquele campo estiver ativado.
4. A recomendação para a interface é iniciar o checkbox **desmarcado por padrão**. Trata-se de recomendação técnica/UX, não de uma decisão explicitamente votada.
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

**Não decidido ainda:** política de atualização de um campo com valor que fica obsoleto no meio da conversa quando a sobrescrita está desabilitada; a regra vigente é preservar o valor existente.

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

### 7.3. Proteção em camadas — recomendações técnicas, não garantia absoluta

- Nunca enviar chave da OpenAI, token da UAZAPI ou credenciais internas ao navegador, ao modelo ou ao WhatsApp.
- Não confiar só na instrução de prompt para impedir vazamento; minimizar dados expostos ao modelo e aplicar controles de saída compatíveis com a política.
- Evitar gravação acidental de dados pessoais e corpos de mensagens em logs, filas de erro e ferramentas de monitoramento.
- Tratar instruções enviadas pelo lead (inclusive tentativas de manipular o assistente) como **conteúdo não confiável**, incapaz de ampliar permissões.
- Registrar e testar casos de vazamento por paráfrase, exibição parcial e resposta indireta. Detecção automática de informações sensíveis tem limitações; **não presumir proteção perfeita** apenas por filtros textuais.

## 8. Quando executar ações — aprovado

Tanto a adição de tags quanto a atualização de campos ocorrem **durante a conversa**, assim que as instruções e as evidências forem suficientes. Não é necessário aguardar que a IA acione a saída `Continuar` ou `Atendimento Humanizado`.

### 8.1. Regras transversais de execução — recomendações técnicas

Para cada ação solicitada pelo modelo, o Laravel deve conferir, no momento da execução:

1. Identidade do workspace, contato, fluxo, versão publicada e bloco.
2. Sessão/execução de IA ainda ativa e ausência de bloqueio por atendimento humano.
3. Recurso habilitado e presente na lista permitida para o bloco.
4. Restrições específicas: já enviou mídia? houve pedido explícito de reenvio? campo permite sobrescrita?
5. Deduplicação/idempotência para chamadas repetidas, webhooks duplicados e execução concorrente.
6. Registro operacional de resultado, sem persistir desnecessariamente o texto integral da conversa.

As alterações confirmadas devem permanecer após a conclusão da IA ou a transferência para atendimento humano. Recomenda-se auditoria com **origem Assistente IA, recurso/identificador, momento, execução e resultado**, mantendo uma política específica para o tratamento de valores pessoais sensíveis em logs de auditoria.

## 9. Contrato técnico sugerido — não é esquema/API aprovados

**Separação de responsabilidades:**

- **Modelo OpenAI:** interpreta mensagem/contexto, conversa com o lead e **solicita ações estruturadas** com base nas instruções.
- **Serviço Laravel:** decide se a ação é válida, persiste alterações autorizadas e chama o adaptador de WhatsApp para envios.
- **Executor de fluxos:** mantém execução ativa, saídas válidas, isolamento e invalidação de operações quando há interrupção/handoff.

Ações internas candidatas (nomes ilustrativos; não definir endpoints por esta seção):

| Ação sugerida | Verificações obrigatórias |
|---|---|
| `send_media(media_id)` | Mídia permitida, arquivo válido, estado da execução, limite/reenvio e política do provedor. |
| `add_contact_tag(tag_id)` | Tag autorizada no bloco e do mesmo workspace; operação idempotente. |
| `set_contact_field(field_id, value)` | Campo autorizado; valor válido e verificação de sobrescrita. |
| `continue_flow()` | Transição única para saída `Continuar` se execução vigente. |
| `request_human_handoff()` | Transição única para `Atendimento Humanizado`, com pausa/cancelamento de envios automáticos. |

A lista é uma **proposta de engenharia**; não representa assinatura real da API OpenAI nem contrato PHP definitivo. O executor deve controlar atomicamente a saída vencedora para evitar que o assistente conclua e abra atendimento humano simultaneamente.

### 9.1. Contexto temporário

- Manter contexto só pelo tempo necessário para sustentar as interações **dessa execução**.
- Não reutilizar a mesma sessão/thread ao reentrar no bloco.
- Encerrar/revogar o contexto operacional e programar a limpeza de eventuais estruturas temporárias ao sair/interromper.
- A estratégia concreta de retenção/limpeza de recursos do provedor OpenAI ainda requer decisão e validação: não afirmar que apagar o estado local equivale a exclusão instantânea em servidores de terceiros.
- Não criar uma cópia permanente do histórico WhatsApp no Laravel para facilitar a IA sem alterar a política de dados existente.

## 10. Interface de configuração sugerida

No painel esquerdo do bloco IA no editor Vue Flow, organizar as opções em grupos:

1. **Assistente:** instruções, modelo e configuração disponível ao administrador; credencial OpenAI gerenciada com segurança no workspace.
2. **Saídas:** orientações/critérios de `Continuar` e `Atendimento Humanizado`.
3. **Mídias:** itens adicionáveis com upload e instrução individual.
4. **Tags:** lista de tags escolhidas, cada uma com instrução “Quando aplicar”.
5. **Campos personalizados:** seleção, instrução por campo e checkbox individual “Permitir sobrescrever”.

**Não incluir nesta versão** controles de notas automáticas. O canvas conserva o bloco compacto e as duas saídas visíveis; o formulário detalhado fica no painel de edição.

Este layout é uma **sugestão de UX** baseada nas decisões funcionais, não um protótipo já validado.

## 11. Cenários mínimos de aceite funcional

| ID | Cenário | Resultado esperado |
|---|---|---|
| IA-01 | IA envia mídia cadastrada pela primeira vez | Envio permitido quando a instrução é atendida e o backend valida. |
| IA-02 | IA tenta reenviar a mesma mídia sem novo pedido do cliente | Envio bloqueado. |
| IA-03 | Cliente solicita novamente mídia já enviada | Reenvio pode ser autorizado naquela execução. |
| IA-04 | Lead entra no mesmo bloco em outra execução | Controle de envio reiniciado e novo contexto de IA. |
| IA-05 | IA tenta enviar mídia não cadastrada no bloco | Operação negada. |
| IA-06 | IA adiciona tag autorizada que o lead ainda não possui | Tag vinculada ao lead correto. |
| IA-07 | IA tenta aplicar tag não autorizada ou remover tag | Operação negada. |
| IA-08 | IA tenta adicionar tag já existente | Nenhuma associação duplicada criada. |
| IA-09 | IA preenche campo autorizado vazio | Valor registrado se houver evidência suficiente. |
| IA-10 | Campo já tem valor e sobrescrita está desmarcada | Valor atual preservado. |
| IA-11 | Campo já tem valor e sobrescrita está marcada | Atualização permitida, validada pelo backend. |
| IA-12 | IA tenta editar campo não selecionado no bloco | Operação negada. |
| IA-13 | Informações na conversa são ambíguas | IA não inventa valor; pode perguntar ao lead. |
| IA-14 | Cliente pede CPF/e-mail/telefone ou parte de dado pessoal cadastrado | IA não revela os valores, mesmo após insistência. |
| IA-15 | IA aplica uma tag ou atualiza campo e depois solicita handoff | Alteração preservada; automação interrompida conforme a regra humana. |
| IA-16 | Job antigo tenta enviar mídia depois de atendimento humano iniciado | Envio bloqueado. |
| IA-17 | IA solicita `Continuar` | Executor usa somente a saída correspondente e encerra a sessão IA. |
| IA-18 | IA solicita atendimento humano | Executor usa somente a saída humana; não executa também `Continuar`. |
| IA-19 | Contato de outro workspace é usado em chamada de ação | Rejeição por isolamento/autorização. |
| IA-20 | Dois eventos/processos tentam a mesma ação simultaneamente | Idempotência e consistência do estado são preservadas. |

## 12. Pontos ainda não decididos — não implementar por suposição

Estes pontos **não anulam** as decisões aprovadas acima, mas devem ser resolvidos na fase de desenho detalhado/implementação:

- Critérios e experiência exata para configurar a conclusão do bloco IA e escolher a saída.
- Modelo(s) disponíveis, parâmetros configuráveis, limites de tokens, uso/custos e comportamento quando a credencial OpenAI estiver inválida ou faltar saldo.
- Timeout/inatividade do bloco IA e política de falhas/interrupção de serviço.
- Limite/tamanho/formato aceito por tipo de mídia, legendas e falhas de entrega na UAZAPI contratada.
- Tratamento de mensagens de áudio, imagem e anexos **recebidos** pelo assistente, distinto do envio de mídias aprovado.
- Tipo e validação de campos personalizados além do texto, caso sejam introduzidos futuramente.
- Regras de concorrência quando uma atualização manual acontecer ao mesmo tempo que uma atualização da IA.
- Tratamento de tag/campo/mídia referenciado(a) que tenha sido excluído(a), desativado(a) ou substituído(a) após a publicação do fluxo.
- Limites de frequência e proteção contra spam de ações dentro de uma única execução.
- Política de retenção operacional e de exclusão de recursos temporários eventualmente criados no provedor de IA.
- **Notas:** resumo da conversa, leitura, criação, edição e política de autoria pela IA ficam expressamente para uma rodada futura.

## 13. Registro das decisões desta rodada

| ID | Decisão | Estado |
|---|---|---|
| IA-D01 | Cadastrar mídias por upload com instrução individual de quando enviar | **Aprovado** |
| IA-D02 | Envio automático de cada mídia uma vez por execução; reenvio se o cliente pedir novamente | **Aprovado** |
| IA-D03 | Permitir somente **adicionar** tags selecionadas no bloco, com instrução por tag | **Aprovado** |
| IA-D04 | Selecionar campos editáveis e configurar sobrescrita **individualmente** por campo | **Aprovado** |
| IA-D05 | Permitir interpretação contextual com evidência suficiente, sem inventar informações | **Aprovado** |
| IA-D06 | IA pode **ler todas** as tags e os campos personalizados do contato; alterar somente os autorizados | **Aprovado** |
| IA-D07 | Tags e campos podem ser modificados **durante a conversa**, sem esperar a saída do bloco | **Aprovado** |
| IA-D08 | IA **não divulga dados pessoais cadastrados**, nem parcialmente, mesmo se o cliente solicitar | **Aprovado** |
| IA-D09 | Não definir agora a lógica de notas automáticas da IA | **Adiado deliberadamente** |
| IA-D10 | Duas saídas: `Continuar` e `Atendimento Humanizado`; sem saída `Encerrar` | **Aprovado em discussão anterior** |
| IA-D11 | Prompt no bloco, modelo por bloco, chave OpenAI custeada pelo cliente e sessão limitada à execução | **Direção aprovada em discussão anterior** |

## 14. Histórico

- **v0.1 — 13/10/2026:** documento independente criado para consolidar o bloco Assistente IA e as decisões sobre mídias, tags, campos personalizados, leitura de dados e privacidade; notas automáticas excluídas da definição atual. O documento de editor de fluxos anterior ainda lista IA fora do seu escopo original e **não foi alterado** por esta criação.
