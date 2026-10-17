# Webhooks Externos — Recebimento e Sincronização de Contatos

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Projeto:** chAIt — SaaS Laravel de atendimento e automações  
> **Documento:** `docs/modulos/webhooks-externos.md`  
> **Versão:** 1.0  
> **Data:** 14/10/2026  
> **Estado:** implementado; decisões de produto e contrato final descritos na seção 9.  
> **Escopo:** receber JSON de plataformas externas para criar/atualizar contatos, campos personalizados e tags.

## 1. Objetivo

Permitir que cada workspace integre serviços externos (por exemplo, plataformas de infoprodutos, formulários e sistemas próprios) sem escrever código no chAIt. Um administrador cria um Webhook, recebe uma URL exclusiva, identifica campos de um payload JSON, configura o mapeamento e define operações de tags, inclusive com condições visuais.

**Distinção importante:** este módulo trata de **Webhooks de entrada de plataformas externas**. Não é o Webhook técnico da UAZAPI que recebe mensagens do WhatsApp; os dois mecanismos não devem compartilhar regras de negócio nem configuração.

### 1.1. Princípios confirmados

- **Vários Webhooks por workspace**, cada qual com URL, payload de referência, mapeamento, regras de tags e estado independentes.
- **Upsert de contatos**: identificar pelo telefone WhatsApp dentro do workspace; criar se não existir, atualizar se existir.
- Em atualizações, **somente campos mapeados com valores válidos e não vazios** são modificados. Ausentes, `null`, strings vazias ou só com espaços não apagam valores existentes.
- Suportar **campos nativos** e **campos personalizados** do contato.
- Permitir **adicionar e remover tags**, inclusive por regras condicionais do tipo **SE → ENTÃO**.
- Interface com **uma página de configuração e quatro seções**, evitando formulários excessivamente complexos.
- Permitir identificar o esquema do JSON tanto por **captura de requisição real** quanto por **colar um JSON de exemplo**.
- Separar **Teste** (sem alterar dados) de **Ativo** (processamento real).
- **Sem configuração adicional de autenticação** por token, assinatura ou cabeçalhos na primeira versão. A URL é única, opaca e gerada automaticamente.
- Webhooks distintos que recebam o mesmo telefone agem sobre **o mesmo contato do workspace**. Se dois eventos atualizarem o mesmo campo, o último **processado com sucesso** prevalece.

**Observação de produto:** não foi definido limite comercial para a quantidade de Webhooks. Não impor um limite arbitrário na UI sem decisão de planos; restrições técnicas de operação continuam possíveis.

## 2. Limites e integração com o SaaS

- **Workspace é o tenant**. Contatos, tags, campos personalizados, regras, recebimentos e logs pertencem ao workspace do Webhook.
- O usuário autenticado só gerencia Webhooks dos workspaces aos quais tem autorização. A autorização administrativa exata ainda será alinhada às permissões existentes.
- O Webhook **não cria outro sistema de contatos, campos ou tags**: reutiliza as entidades oficiais do CRM/chat/fluxos.
- No escopo aprovado, recebimento externo **não dispara automaticamente fluxo, palavra-chave, atendimento humano ou mensagem WhatsApp**. Gatilhos externos de automação são uma evolução separada; não estão aprovados nesta especificação.
- O histórico completo de mensagens do WhatsApp continua na UAZAPI. A prévia e o conteúdo temporário permitidos no modo de tempo real seguem [Chat em tempo real](../operacao/chat-realtime.md); o payload deste módulo externo tem regras próprias de captura e retenção abaixo. Valores dos campos de contato e operações de tags são dados do CRM e não devem ser confundidos com o histórico de mensagens.
- Stack do projeto: **Laravel**; interface aprovada **Vue 3 + Inertia.js**; processamento assíncrono por **Redis/Horizon**, quando implantados. Não presumir banco de dados ou schema reais antes de verificar o repositório.

## 3. Jornada do usuário e design

### 3.1. Listagem de Webhooks

Acesso por **Configurações → Webhooks**, na barra de abas após OpenAI, para proprietários e administradores. A listagem e a configuração utilizam o layout de Configurações, com Webhooks selecionado. As rotas existentes por workspace continuam disponíveis e o retorno do editor respeita o workspace do Webhook.

Tela do workspace contendo:

| Elemento          | Comportamento                                                                        |
| ----------------- | ------------------------------------------------------------------------------------ |
| **Criar Webhook** | Abre cadastro; informa-se um nome.                                                   |
| Nome              | Identificação humana: `Hotmart`, `Kiwify`, `Formulário de inscrição`.                |
| URL               | Exclusiva por Webhook, gerada no backend automaticamente.                            |
| Estado            | **Teste** ou **Ativo**, visível na lista.                                            |
| Ações             | Abrir configurações e copiar URL; ações administrativas adicionais exigem definição. |

Cada item da lista abre **a própria configuração**. Não herdar mapeamentos ou tags de outros Webhooks sem ação explícita.

### 3.2. Página única de configuração — quatro seções

**Cabeçalho:** nome do Webhook, estado atual, ação `Salvar` e controle de ativação. Exibir avisos de validação próximos aos campos afetados.

**1. URL do Webhook**

- Mostrar URL já gerada, com ação `Copiar`.
- Texto simples orientando onde colar na plataforma externa.
- Não exigir que o administrador cadastre token, cabeçalho ou assinatura.

**2. Dados recebidos (Payload)**

- Opção **Capturar automaticamente**: instruir o usuário a enviar uma requisição de teste à URL e exibir o JSON/estrutura detectada.
- Opção **Colar JSON**: editor de texto para inserir manualmente um exemplo; validar JSON e detectar a estrutura.
- Listar os **caminhos dos campos** detectados, com nomes legíveis, inclusive propriedades aninhadas.
- Mensagens claras para `JSON inválido`, `nenhuma requisição recebida`, `estrutura sem campos utilizáveis` e `formato não suportado`.
- Durante captura em **Teste**, nenhum dado de contato/tag é alterado.

**3. Campos do contato**

- Exibir linhas de mapeamento: **campo de origem do payload → campo de destino no contato**.
- Origens escolhidas em seletor com os campos identificados; não exigir digitar expressões JSON.
- Destinos incluem os campos nativos disponíveis no CRM e campos personalizados existentes; permitir **criar campo personalizado** a partir desta seção, sem sair do contexto.
- Exigir mapeamento para **Telefone WhatsApp**, usado para localizar o contato.
- Mostrar sinalização para origem inexistente, destino duplicado e tipos incompatíveis; impedir ativação de configuração inválida.
- Campos mapeados, porém não enviados em determinado evento, não são apagados.

**4. Tags e condições**

- Configuração visual em cartões de **SE → ENTÃO**.
- **SE (opcional):** escolher um campo do payload, um operador de comparação e o valor esperado, por meio de seletores/formulários. Exemplo: `compra.status` **é igual a** `approved`.
- **ENTÃO:** selecionar uma ou mais tags existentes para **Adicionar** e/ou **Remover**.
- Oferecer **Executar sempre** para operações sem condição.
- Permitir várias regras independentes no mesmo Webhook; cada regra decide suas próprias ações. Tags são selecionadas do cadastro do workspace — **não criar tags automaticamente a partir de valores desconhecidos do payload**.
- Exemplo: `approved` → adicionar `Cliente`, remover `Interessado`; `refunded` → remover `Cliente`, adicionar `Reembolsado`.
- Uma regra não deve exigir repetir a condição para cada tag; agrupar várias ações na mesma regra.

**Diretriz de experiência:** interface clara, neutra, concisa e consistente com a identidade visual do produto. Em celular, preservar as mesmas quatro seções empilhadas, sem criar outro fluxo de configuração.

### 3.3. Estados de utilização

| Estado    | Requisição HTTP externa                  | Efeitos sobre contatos/tags                 |
| --------- | ---------------------------------------- | ------------------------------------------- |
| **Teste** | Recebida para inspeção/captura de campos | **Nenhum**                                  |
| **Ativo** | Validada e processada                    | Executa upsert, mapeamento e regras de tags |

- Webhook novo **inicia em Teste**.
- Um teste acionado na interface deve ser uma **simulação sem efeitos**, inclusive quando a configuração estiver ativa. Isso não muda o tratamento das requisições externas reais recebidas por um Webhook **Ativo**.
- A ativação exige URL válida, campo de telefone mapeado e configuração coerente.
- A UI deve diferenciar `recebido`, `simulado`, `processado`, `ignorado` e `erro`; uma requisição HTTP aceita não significa, por si só, que o contato foi atualizado.

## 4. Exemplo de payload e mapeamento

**Exemplo ilustrativo (não é contrato de uma plataforma específica):**

```json
{
    "cliente": {
        "nome": "Maria Silva",
        "telefone": "5527999999999",
        "email": "maria@example.com"
    },
    "compra": {
        "produto": "Curso de Inglês",
        "status": "approved"
    }
}
```

| Origem             | Destino                                |
| ------------------ | -------------------------------------- |
| `cliente.telefone` | **Telefone WhatsApp** (obrigatório)    |
| `cliente.nome`     | Nome                                   |
| `cliente.email`    | E-mail                                 |
| `compra.produto`   | Campo personalizado `Produto`          |
| `compra.status`    | Campo personalizado `Status da compra` |

Regras ilustrativas:

1. **SE** `compra.status` = `approved` **ENTÃO** adicionar `Cliente` e remover `Interessado`.
2. **SE** `compra.status` = `refunded` **ENTÃO** remover `Cliente` e adicionar `Reembolsado`.

Se a plataforma enviar **outro evento** sem `cliente.email`, o e-mail existente **permanece**. Se enviar um telefone não identificável, **não executar upsert nem tags para aquela requisição**.

## 5. Contrato de processamento

### 5.1. Localização/criação do contato (aprovado)

1. Receber requisição na URL exclusiva do Webhook.
2. Derivar `webhook_id` e, por ele, `workspace_id` no backend; **nunca** confiar em `workspace_id` declarado no payload para autorizar escrita.
3. Em modo **Teste**, identificar os campos e mostrar o resultado, sem mutações.
4. Em modo **Ativo**, validar estrutura e localizar o campo do telefone configurado.
5. Normalizar o telefone para a **mesma representação canônica** utilizada pelo módulo de contatos, com validação.
6. Se telefone estiver ausente, vazio, ambíguo ou inválido, **não criar/alterar contato nem aplicar tags**; registrar erro de processamento.
7. Buscar contato por `(workspace_id, telefone_normalizado)`; se existir, atualizar; caso contrário, criar.
8. Persistir apenas campos mapeados com valores aceitos, sem apagar campos não enviados.
9. Avaliar regras de tags com base no payload do evento recebido e executar as ações correspondentes.
10. Registrar resultado operacional e encerrar.

**Telefone:** o número deve conter DDI. Aceitar `+DDI`, `00DDI` ou dígitos tratados como internacionais, validar com libphonenumber sem país padrão e guardar E.164 sem `+`. Não acrescentar `55` nem procurar por sufixo. Dígitos sem prefixo são declarados internacionais pelo remetente; se coincidirem com um número válido de outro país, não é possível deduzir a intenção original.

### 5.2. Semântica de valores (aprovado + detalhamento técnico proposto)

| Valor da origem     | Tratamento recomendado                                                             |
| ------------------- | ---------------------------------------------------------------------------------- |
| Propriedade ausente | Ignorar mapeamento; manter valor atual.                                            |
| `null`              | Ignorar mapeamento; não apagar.                                                    |
| `""` ou `"   "`     | Ignorar mapeamento; não apagar.                                                    |
| Texto não vazio     | Atualizar, após validação do campo destino.                                        |
| `0` ou `false`      | **Não tratar como vazio por engano**; avaliar compatibilidade com o tipo do campo. |
| Valor incompatível  | Não gravar o valor inválido; registrar falha/aviso de campo.                       |

O briefing dos fluxos prevê começar os **campos personalizados com tipo texto**, podendo expandir depois. Validar compatibilidade com o modelo implementado antes de oferecer tipos adicionais aqui.

### 5.3. Tags (aprovado)

- `Adicionar` tag já presente não cria duplicata.
- `Remover` tag ausente não é erro.
- Apenas tags do mesmo workspace podem ser referenciadas.
- Cada condição lê a requisição atual, não um valor antigo armazenado no contato.
- Regras `Executar sempre` aplicam as operações em todos os eventos válidos daquele Webhook.
- Condição não atendida não executa suas operações.
- A UI alerta sobre ações opostas entre regras. Executar de cima para baixo: a última ação da última regra atendida prevalece. Adicionar e remover a mesma tag na mesma regra é inválido.

### 5.4. Concorrência, duplicidade e múltiplos Webhooks

- Um contato com o mesmo telefone **não é recriado** a cada Webhook: exclusividade e procura são por workspace + telefone canônico.
- Contatos de **workspaces diferentes não se misturam**, mesmo com o mesmo número.
- Dois eventos simultâneos não podem gerar dois contatos para a mesma chave; usar restrição única e controle transacional.
- Uma atualização do Webhook A pode ser seguida por outra do Webhook B; nos campos em conflito, prevalece o valor da **última operação confirmada no banco**, conforme aprovado. Não significa necessariamente o evento mais recente _na plataforma de origem_.
- Aplicar tags dentro da mesma unidade lógica do upsert sempre que viável, evitando contato atualizado com operação de tags parcialmente contraditória.
- Requisições **repetidas** não devem duplicar contatos ou vínculos de tags. Esta versão não deduplica eventos: todas as requisições válidas são processadas, inclusive retransmissões.
- **Decisão:** não usar hash nem identificador externo para deduplicar nesta versão; JSONs idênticos podem representar eventos legítimos distintos.

## 6. Referência de arquitetura Laravel

Separar **recepção**, **interpretação/mapeamento** e **aplicação no CRM**. O front-end nunca executa upserts com base em dados arbitrários enviados pelo cliente; toda regra é validada no backend.

### 6.1. Componentes lógicos sugeridos

| Componente                  | Responsabilidade                                                          |
| --------------------------- | ------------------------------------------------------------------------- |
| `ExternalWebhookController` | Resolver URL, validar requisição, distinguir Teste/Ativo, responder HTTP. |
| `ExternalWebhookService`    | Orquestrar recebimento e processamento.                                   |
| `PayloadFieldExtractor`     | Identificar caminhos de campos do JSON para os seletores.                 |
| `WebhookMappingService`     | Resolver caminhos e validar destinos/valores.                             |
| `ContactUpsertService`      | Localizar/criar/atualizar contato no workspace.                           |
| `WebhookTagRuleEvaluator`   | Avaliar SE → ENTÃO e aplicar operações de tags.                           |
| `ProcessExternalWebhook`    | Job do Horizon/Redis para processamento assíncrono, se adotado.           |

**Implementação:** `ExternalWebhookController`, `PayloadFields`, `WebhookDefinition`, `WebhookProcessor`, `InternationalPhone` e `ContactIdentity`. Os componentes lógicos acima indicam responsabilidades, não classes adicionais. O processamento é síncrono.

### 6.2. Entidades implementadas

| Entidade                    | Conteúdo mínimo                                                                                                                                             |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `external_webhooks`         | Workspace, nome, chave opaca, estado, mapeamentos e regras ordenadas em JSON, campos detectados, último exemplo criptografado, horário e janela de captura. |
| `external_webhook_receipts` | Workspace, Webhook, estado, contato opcional, duração, códigos de erro/avisos e timestamps. Sem payload; retenção de 30 dias.                               |

**Não duplicar** tabelas de `contacts`, `tags`, `contact_tags` ou `custom_fields` existentes. Campos personalizados são definidos por workspace e **seus valores pertencem ao contato**, como previsto no editor de fluxos.

### 6.3. Recepção HTTP síncrona

`POST /webhooks/{endpoint_key}` retorna JSON, sem login ou CSRF. A chave tem 32 bytes aleatórios, representados por 64 caracteres hexadecimais. O workspace é resolvido exclusivamente pela configuração persistida.

Em Teste, o último objeto JSON válido é capturado automaticamente. Em Ativo, só se retém exemplo quando o administrador arma a próxima captura por dez minutos; o evento continua sendo processado. Um exemplo capturado permanece disponível mesmo quando o CRM rejeita o telefone. Corpos JSON inválidos não substituem o exemplo anterior.

O exemplo é criptografado e mantido até substituir ou apagar. Apagar mantém estrutura, configuração e histórico. A consulta periódica não substitui o formulário nem JSON digitado ainda não importado.

As alterações de contato, campos e tags são atômicas. Falha interna gera rollback e mensagem pública genérica. Nenhum JSON é transportado em filas/Horizon.

### 6.4. Proteção operacional interna — sem complexidade na UI

O usuário decidiu **não incluir configuração de autenticação extra** no MVP. Manter a interface simples **não significa** abandonar cuidados mínimos do servidor:

- Gerar chaves de URL aleatórias, não sequenciais, suficientemente longas e únicas; não colocar `workspace_id` previsível no caminho como substituto da chave.
- Usar HTTPS e não exibir credenciais técnicas em logs.
- Impor limites razoáveis de tamanho de corpo, profundidade JSON e frequência para reduzir falhas/abuso; calibrar sem bloquear integrações legítimas.
- Validar todas as referências de tags/campos no workspace correto e manter autorização administrativa para editar configurações.
- Restringir dados de debug e logs, sobretudo telefones, CPF, e-mail e payloads de compras.
- Saber que **qualquer pessoa com a URL pode enviar dados falsos** ao contato. Esse risco decorre da escolha de não autenticar a origem e **não é eliminado** por URL aleatória; deixar explícito no escopo do produto.

Não incluir telas de configuração de cabeçalhos, OAuth, HMAC, IPs permitidos ou outro mecanismo de autenticação nesta versão.

## 7. Validações e casos de falha

| Situação                                               | Resultado esperado                                                                      |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------- |
| JSON inválido ou formato não aceito                    | Não altera dados; indica erro de recepção/validação.                                    |
| Webhook em Teste recebe POST                           | Exibe estrutura para configurar, **sem upsert nem tags**.                               |
| Webhook Ativo sem mapeamento de telefone               | **Não permitir ativação**; configuração incompleta.                                     |
| Telefone ausente/inválido                              | Não cria/atualiza contato nem aplica tags; registra falha.                              |
| Mesmo telefone já existe                               | Atualiza apenas os campos válidos mapeados, preservando os demais.                      |
| Mesmo telefone não existe                              | Cria um contato no workspace e aplica os campos e tags válidos.                         |
| Payload não tem um campo mapeado opcional              | Preserva o valor existente do campo.                                                    |
| Condição de tag falsa                                  | Não executa as operações daquela regra.                                                 |
| Adicionar tag existente / remover ausente              | Operação idempotente; sem duplicação ou erro desnecessário.                             |
| Dois Webhooks apontam para o mesmo telefone            | Um único contato dentro do workspace, com alterações independentes.                     |
| POST para chave desconhecida ou Webhook não disponível | Não processa contato; resposta HTTP de não disponibilidade conforme contrato a definir. |
| Requisições concorrentes                               | Não duplicam contato; respeitam consistência da transação.                              |

## 8. Critérios de aceite

- [x] Um workspace consegue criar **múltiplos** Webhooks com URLs distintas e configurações independentes.
- [x] Criar Webhook gera URL automaticamente, sem pedir token/cabeçalho ao usuário.
- [x] A lista apresenta nome, URL identificável e status **Teste/Ativo**.
- [x] Captura por POST e colagem de JSON identificam campos aninhados e permitem selecioná-los visualmente.
- [x] O administrador seleciona campos de origem e destinos nativos ou personalizados; consegue criar campo personalizado na configuração.
- [x] O telefone WhatsApp é obrigatório para ativar o processamento de contatos.
- [x] Em **Teste**, não é criado/atualizado contato e nenhuma tag é alterada.
- [x] O mesmo telefone dentro do workspace faz **upsert**, sem criar duplicata; outro workspace mantém cadastro isolado.
- [x] Valores opcionais ausentes, nulos ou vazios **não apagam** dados existentes.
- [x] Condições `SE → ENTÃO` permitem adicionar/remover várias tags; regras incondicionais também funcionam.
- [x] Tags são selecionadas do workspace, sem autocriação por dados externos.
- [x] Eventos não válidos são registrados como falha, sem afetar contato/tag de terceiros.
- [x] Recebimentos simultâneos não criam contatos duplicados nem cruzam dados entre workspaces.
- [x] A recepção externa **não inicia automaticamente fluxo de conversa ou atendimento humano**.
- [x] A interface não exige saber JSONPath nem programar expressões para fazer mapeamentos comuns.

## 9. Decisões finais de implementação — 14/10/2026

- **Permissões:** proprietários e administradores (`WorkspaceRole::Admin` como mínimo), inclusive exemplos, simulações e histórico. Permissão de fluxos não concede acesso ao módulo.
- **Ações:** criar, renomear, salvar e alternar Teste/Ativo. Sem exclusão ou regeneração de URL.
- **Formato:** objeto na raiz, um contato; objetos e arrays internos com índices fixos. Sem lote, wildcard ou expressões JSONPath. Caminhos são segmentos tipados, evitando confundir pontos em propriedades e índices de arrays.
- **Mapeamento:** telefone, nome, e-mail, CPF e campos personalizados de texto. Ativação exige configuração completa e fontes presentes no exemplo. Valores opcionais inválidos são ignorados com aviso por mapeamento, preservando o cadastro; `0` e `false` podem virar texto.
- **Condições:** uma igualdade por regra ou Executar sempre. Sensível a letras e tipos; números JSON inteiros e decimais são o mesmo tipo. Valor esperado pode ser texto, número ou booleano. Campo ausente não satisfaz igualdade.
- **Concorrência:** índice único em contatos por workspace/telefone canônico; inserção tolerante a conflito seguida de bloqueio transacional. A UAZAPI reutiliza a mesma identidade. LID não é tratado como número.
- **Migração:** verifica todas as chaves antes de alterar telefones válidos. Colisões interrompem com IDs de contato/workspace, sem expor telefones ou mesclar dados. Telefones legados não interpretáveis não recebem um país presumido.
- **HTTP:** `200` captura/processamento; `400` JSON inválido; `404` chave desconhecida; `413` tamanho; `415` Content-Type; `422` estrutura/configuração/telefone inválido; `429` frequência, com Retry-After; `500` falha interna. Métodos diferentes de POST seguem `405` do roteador.
- **Estados:** recebido, simulado, processado, sem alterações (`ignored`) e erro. Simulação utiliza a configuração atual do formulário e nunca escreve no CRM nem muda o estado.
- **Proteções:** 256 KiB, profundidade 32, mil campos e 120 requisições/minuto por Webhook, configuráveis pelo servidor. Sem limite comercial de Webhooks. Requisições recusadas pelo rate limit não criam histórico para evitar crescimento abusivo.
- **Operação:** histórico de metadados por 30 dias, últimos vinte registros na configuração. `webhooks:prune-receipts` é agendado diariamente. Logs não incluem valores, JSON, URL ou exceções SQL com bindings.
- **Sem deduplicação:** nenhuma seleção de ID externo ou hash do payload. Contato e vínculos de tags permanecem únicos, mas cada evento é avaliado novamente.

### Validação

Testes Pest de permissões, isolamento, contratos, simulação, captura, valores, regras, rollback, UAZAPI e migração; teste multiprocesso em PostgreSQL descartável; Playwright e inspeção visual dos dois temas nas cinco larguras do padrão de layout. Para comandos e implantação, consulte [deploy](../operacao/deploy.md#webhooks-externos).

## 10. Fora do escopo desta versão

- Webhooks de saída para notificar terceiros.
- Acionamento de fluxo visual, envio de WhatsApp ou abertura de atendimento humano diretamente por eventos externos.
- Integração nativa específica com Hotmart/Kiwify/Eduzz (o MVP recebe JSON genérico; os exemplos não são conectores certificados).
- Autenticação configurável por assinatura, headers secretos, IP allowlist ou OAuth.
- Transformações complexas de JSON, cálculos, scripts do usuário e motor de expressões.
- Processamento em lote de múltiplos contatos por evento sem decisão posterior.
- Regras comerciais de limites por plano e cobrança por evento.

## 11. Documentos relacionados

- `docs/README.md` — índice e diretrizes de documentação do chAIt.
- `docs/modulos/chat-multiatendimento.md` — isolamento de workspaces, contatos e campos básicos.
- `docs/modulos/editor-de-fluxos.md` — tags/campos personalizados compartilhados e separação dos gatilhos do executor.
- `docs/integracoes/uazapi.md` — Webhook técnico de mensagens, distinto deste recurso externo.
- `docs/planejamento/decisoes.md` — registro geral de decisões; incluir referência a este módulo quando integrar a documentação no repositório.

> **Manutenção:** ao incorporar no repositório, acrescentar este documento ao `docs/README.md`. Não alterar o escopo aprovado do editor de fluxos retroativamente: a entrada por Webhook constitui um **módulo novo** e não aprova, por si só, gatilhos externos para automações.

## 12. Histórico de versões

- **v0.1 — 13/10/2026:** consolidadas as decisões da discussão sobre URLs por Webhook, upsert por telefone, preservação de campos, múltiplos Webhooks, mapeamento, campos personalizados, tags com condições, design em quatro seções, duas formas de capturar payload, Teste/Ativo e ausência de autenticação extra configurável.

- **v1.0 — 14/10/2026:** módulo implementado com processamento síncrono, política de telefone internacional, exemplos criptografados sem expiração automática, condições de igualdade, ordem de tags, arrays internos, permissões administrativas e ausência de deduplicação.

### Evidências locais — 14/10/2026

- Suíte Pest completa: 317 testes / 1.735 assertions; módulo: 33 testes / 221 assertions. Os 32 cenários iniciais (215 assertions) também foram aprovados em PostgreSQL descartável.
- Concorrência: quatro rodadas com dois processos e Webhooks distintos; um contato, um vínculo de tag e atualização conjunta de campo nativo/personalizado, com última confirmação prevalecendo.
- Playwright completo: 32 cenários aprovados; cinco são do módulo de Webhooks. Inspeção visual claro/escuro nas larguras 390, 768, 1024, 1280 e 1440 px.
- Lint, tipos, formatação de frontend, build e 14 testes frontend aprovados. Pint dos arquivos deste módulo aprovado; o Pint global detectou alterações de formatação em `User.php` e `FlowGraphValidator.php`, modificados paralelamente fora deste escopo. Uma verificação posterior de Prettier também apontou `settings/openai.vue`, sem alterações deste módulo.
- Migração do módulo aplicada na base local; contatos preservados e telefones válidos normalizados.

### Navegação em Configurações — 14/10/2026

Webhooks passou a ser uma aba após OpenAI na barra de Configurações, com entrada em `/settings/webhooks` para o workspace atual. O atalho lateral foi removido. Lista e editor reutilizam `SettingsLayout`; a aba Webhooks e o item Configurações ficam ativos, incluindo nas rotas por workspace. URLs externas e endpoints administrativos permanecem; Voltar respeita o workspace do Webhook. A barra revela a aba ativa no celular e quando a largura muda.

Validação desta mudança: 60 testes backend aprovados, tipos/build e formatação dos arquivos alterados aprovados; cenários de Webhooks, navegação compartilhada, permissões e descarte de rascunho aprovados no navegador. A execução ampliada encontrou três falhas fora deste escopo nos testes de salvamento/permissões do editor de fluxos, confirmadas em repetição isolada (conflito de edição).
