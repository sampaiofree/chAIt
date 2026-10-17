> **Versão alternativa preservada:** consulta documental sobre menus interativos. Consulte a [integração principal](../integracoes/uazapi.md) para o estado da implementação.

# Integração WhatsApp — UAZAPI

> **Versão:** 0.3  
> **Consulta documental:** 2026-10-14 (Menu interativo; demais referências consultadas anteriormente em 12/10)  
> **Estado:** provedor aprovado; contrato e comportamento do servidor contratado ainda precisam de validação.

## Direção aprovada

- UAZAPI é o provedor inicial do WhatsApp.
- Cada workspace terá um número de WhatsApp na primeira versão.
- Laravel intermedeia chamadas e protege as credenciais.
- Chat e multiatendimento precedem o editor e o executor de fluxos.
- Credenciais e servidor da instância precisam ser definidos no ambiente de implementação; não registrar segredos neste briefing.

## Fontes oficiais

- [Documentação principal](https://docs.uazapi.com/).
- [Índice para agentes](https://docs.uazapi.com/llms.txt), identificado como API 2.6.4 na consulta; isso não determina a versão contratada.
- [Contrato OpenAPI](https://docs.uazapi.com/openapi-bundled.json).
- [Instâncias e sessões](https://docs.uazapi.com/docs/instances).
- [Webhooks](https://docs.uazapi.com/docs/integrations-webhooks).
- [Erros e confiabilidade](https://docs.uazapi.com/docs/errors-and-retries).
- [Histórico e retenção](https://docs.uazapi.com/docs/collections/message-history-retention.md).
- [Consulta de mensagens](https://docs.uazapi.com/reference/findMessages.md).
- [Consulta de chats](https://docs.uazapi.com/reference/findChats.md).
- [Enviar menu interativo — `POST /send/menu`](https://docs.uazapi.com/endpoint/post/send~menu).
- [Enviar texto — `POST /send/text`](https://docs.uazapi.com/endpoint/post/send~text).
- [Evento webhook `messages`](https://docs.uazapi.com/webhook/messages).
- [Modelo de mensagem e `buttonOrListid` — `POST /message/find`](https://docs.uazapi.com/endpoint/post/message~find).

## Capacidades documentadas, sem teste no servidor contratado

| Recurso | Referência | Validação necessária |
|---|---|---|
| Sessão WhatsApp por instância | Guia de instâncias | Criação, pareamento, status e reconexão. |
| Listagem de chats com filtros e paginação | Consulta de chats | Dados disponíveis e composição com permissões e atribuições locais. |
| Consulta de mensagens por chat, com paginação | Consulta de mensagens | Identificadores, ordenação, atualização e mídias. |
| Recuperação de histórico anterior | Guia de histórico | Dependência do celular, lotes, timeout e indisponibilidade. |
| Eventos de conexão, mensagens, entrega/leitura, histórico e presença | Guia de webhooks | Payloads efetivos, correlação, duplicidade e ordem. |
| Menus de botões e listas | `POST /send/menu` | Aceitação e renderização em clientes reais; limites por tipo. |
| Identificação de seleção interativa | Modelo `Message`: `buttonOrListid`; webhook `messages` | Confirmar presença consistente em respostas de Botões/Lista, distinguindo seleção de texto digitado. |

Recursos do provedor não aprovam automaticamente sua inclusão na interface. Status, responsáveis e notas internas do SaaS seguem os contratos do [multiatendimento](../modulos/chat-multiatendimento.md).

## Histórico: limitação que afeta o produto

A documentação informa retenção local de **até sete dias**, possivelmente menor com alto volume. A recuperação de mensagens anteriores depende do aparelho principal e não tem retorno garantido. Solicitar histórico não aumenta permanentemente a janela de retenção. [Fonte oficial](https://docs.uazapi.com/docs/collections/message-history-retention.md).

**Implicação para o briefing:** manter o histórico exclusivamente no provedor limita a consulta de conversas antigas. Essa é uma conclusão para o produto a partir da regra documentada, não um teste de disponibilidade do servidor contratado.

**Regra confirmada em 12/10/2026:** não salvar conteúdo/histórico de mensagens no Laravel e aceitar o histórico limitado da UAZAPI, conforme decisão B-06 no [registro de decisões](../planejamento/decisoes.md). Histórico operacional de atendimento e notas internas são dados distintos e permanecem no SaaS conforme suas regras. A interface não deve prometer acesso permanente ou completo às mensagens antigas.

## Menus de fluxo — verificação documental em 14/10/2026

**Decisão do produto (não é garantia do provedor):** o bloco [Menu do editor de fluxos](../modulos/editor-de-fluxos.md) terá três formatos (`Botões`, `Lista`, `Numérico`), uma saída por escolha e **somente a saída adicional `Continuar`** por limite de erros ou expiração da espera. Botões e Lista **não** aceitarão escolha digitada por texto ou número; Numérico aceita número correspondente.

### O que está documentado publicamente

| Evidência | Consequência para a integração | Status |
|---|---|---|
| [`POST /send/menu`](https://docs.uazapi.com/endpoint/post/send~menu) envia `type: "button"` com `choices: ["Rótulo|id"]` | Cada resposta do bloco deve ter ID próprio; não depender de texto para mapear saídas. | Documentado; **envio real não testado**. |
| Mesmo endpoint envia `type: "list"` com `listButton` e `choices: ["Rótulo|id|descrição"]` e marcadores `[Seção]` | É possível planejar a mesma estrutura lógica de opções para Lista. | Documentado; **envio real não testado**. |
| [`POST /send/text`](https://docs.uazapi.com/endpoint/post/send~text) envia texto | O formato Numérico usa texto simples; a numeração e validação são responsabilidade do Laravel. | Documentado. |
| Modelo [`Message`](https://docs.uazapi.com/endpoint/post/message~find) inclui `buttonOrListid` como ID do botão/item selecionado | Existe sinal específico para distinguir uma escolha interativa de uma mensagem livre. | Campo **documentado no modelo**; não é promessa de presença em todo webhook. |
| [`messages`](https://docs.uazapi.com/webhook/messages) entrega `message` com estrutura variável conforme tipo e origem | Não se deve assumir que `buttonOrListid` está presente em todos os eventos; o payload real é condição de aceite. | Evento documentado; **correlação de cliques não homologada**. |
| [`POST /send/menu`](https://docs.uazapi.com/endpoint/post/send~menu) alerta que Botões/Lista podem ser descontinuados e que mistura de resposta com URL/chamada/cópia causa problema no WhatsApp Web | Evitar misturas no Menu v1 e oferecer troca de formato, sem perder saídas lógicas. | Risco declarado pelo fornecedor. |

### Estratégia de implementação recomendada, ainda não validada em produção

1. Usar IDs internos estáveis de opção e identificar a execução do Menu que está aguardando. Em Botões/Lista, selecionar **somente** por ID de clique interativo inequívoco, não por `message.text` igual ao rótulo ou número digitado.
2. Exigir vínculo válido entre evento, conexão/workspace, lead, execução ativa, opção e mensagem interativa vigente; ignorar evento antigo, duplicado, enviado pela própria instância ou sem identificação adequada. Validar identificadores `message.id` e `message.messageid` como strings.
3. Usar `POST /send/text` para o Numérico; aceitar exclusivamente um número de opção válido no Menu ativo. Textos que coincidam com nomes das opções são inválidos.
4. Ao detectar **falha confirmada** do envio interativo, tentar fallback numérico e alterar o modo efetivo daquela espera apenas após envio seguro. Resposta HTTP bem-sucedida **não** prova que o botão apareceu ao usuário; timeout de chamada **não** prova que a mensagem falhou — evitar duplicação por reenvio precipitado.
5. A mensagem inválida deve contar uma tentativa elegível sem salvar seu conteúdo. Tempo esgotado ou limite de erros atingido dispara uma única saída `Continuar`, com transição atômica.
6. Não permitir que o clique em menu expirado/interrompido, resposta fora de ordem, gatilho humano ou job atrasado modifique uma execução já encerrada.

**Homologação obrigatória:** capturar exemplares de eventos reais de clique em Botões e Lista e compará-los com mensagens normais de mesmo texto, testando Android/iOS e WhatsApp Web conforme público real, IDs, menus antigos, limites, recebimento duplicado, expiração, reconexão, erros do envio e fallback. Se não existir seleção inequívoca no webhook, **não ativar Botões/Lista interpretando texto como clique**.

**Limitação conhecida:** não foi chamada uma instância real nem comprovada a presença do campo em todos os clientes; não declarar Botões/Lista como funcionamento operacional garantido.

## Pontos de integração a validar

- Confirmar versão, Server URL, instância, autenticação e contrato aplicáveis ao servidor contratado.
- Validar vínculo entre instância e workspace no backend; proteger tokens e evitar logs do payload completo. [Webhooks](https://docs.uazapi.com/docs/integrations-webhooks).
- Confirmar deduplicação e reconciliação por evento; a documentação não define um identificador universal para todos os webhooks. [Webhooks](https://docs.uazapi.com/docs/integrations-webhooks).
- Evitar repetir envios após timeout sem consultar o estado, pois o primeiro envio pode ter sido processado. [Confiabilidade](https://docs.uazapi.com/docs/errors-and-retries).
- Definir processamento assíncrono compatível com a política de persistência; não colocar corpos de mensagens em filas duráveis ou logs por suposição.
- Validar histórico, reconexão, expiração de mídia, limites e estados de indisponibilidade antes do aceite.

Nenhum endpoint foi chamado em uma instância do usuário e nenhuma conexão foi criada ou modificada nesta fase.

## Histórico de versões

- **v0.3 — 2026-10-14:** verificado contrato público de envio de Menu e campo de seleção `buttonOrListid`; registrada diferença entre evidência documental e webhook real, mais limites e procedimentos de homologação.
- **v0.2 — 2026-10-12:** confirmada manutenção do histórico exclusivamente na UAZAPI, com aceitação explícita da retenção limitada; validação operacional do servidor continua pendente.
- **v0.1 — 2026-10-12:** provedor confirmado, fontes oficiais registradas e retenção limitada identificada como dependência de decisão do produto.
