# Diagnóstico de mensagens UAZAPI no primeiro deploy

## Evidência atual e limite

Na conexão 1 do workspace 1, `activated_at = 2026-10-17 20:30:31 UTC`. Os nove eventos informados como `processed` comprovam que seus jobs terminaram; não comprovam que eram mensagens recebidas elegíveis. Apenas um tinha `event_type = messages`. As nove threads anteriores, sem `last_inbound_at`, não demonstram recebimento após o corte: foram criadas antes deste fluxo. O payload de um evento `processed` é descartado quando não há consumidores pendentes; se já estiver nulo, o banco atual não permite classificar esse evento antigo. Não atribuir a causa a timezone, Horizon ou interface sem a próxima entrega identificada.

## Caminho implementado

`POST /webhooks/uazapi/{connection}/{secret}` usa o grupo `web`, com exceção de CSRF para `webhooks/uazapi/*`; não exige login. O controller compara o hash do segredo da URL e, quando o corpo traz `token`, compara-o com o token da instância. `InboundEvents::accept` exige JSON de até 256 KiB, `EventType` permitido, ID de mensagem e chat para `messages`, normaliza o envelope para armazenamento criptografado em `uazapi_inbox` e deduplica por conexão, tipo, chat e `messageid`. O HTTP 200 devolve `receipt_id` após a aceitação durável. Entrega repetida pode devolver o mesmo ID sem nova linha; a coluna `delivery_count` contabiliza esse caso após a migração de diagnóstico. Respostas 404, 413, 415 e 422 não significam aceitação na inbox.

`ProcessInboundEvent` consome a fila `inbound`. Eventos `connection` atualizam estado e ativação; `messages_update` só publica recibos de conversas conhecidas; `chats` só atualiza uma conversa conhecida. Para `messages`, a criação exige `fromMe === false`, timestamp Unix em milissegundos igual ou posterior a `activated_at`, conversa individual, JID `dígitos@s.whatsapp.net` e telefone validado. A projeção cria ou reutiliza contato, canal e thread com status inicial `bot`; os fluxos só recebem uma mensagem de entrada depois de existir uma thread. Um evento pode terminar `processed` mesmo que essas condições não sejam atendidas.

O timezone da aplicação é UTC. `activated_at` é gravado em UTC e `messageTimestamp` é convertido a UTC antes da comparação. Um timestamp em segundos é classificado `invalid_timestamp`; `messages_update.event.Timestamp` em segundos não entra nessa comparação. Na fração de milissegundo do instante da ativação, uma mensagem arredondada pelo provedor ao milissegundo anterior pode ficar antes do corte; isso não explica um evento ocorrido minutos depois. Para conferir um timestamp conhecido sem revelar a mensagem: `SELECT to_timestamp(1788868800000 / 1000.0) AT TIME ZONE 'UTC';`, substituindo apenas o número.

A documentação atual da UAZAPI descreve `message.chatid`, `message.fromMe` booleano e `message.messageTimestamp` em milissegundos para o evento [`messages`](https://docs.uazapi.com/webhook/messages). O `chat.id` do snapshot é um ID local, enquanto `chat.wa_chatid` é o JID. O código foi corrigido para priorizar `message.chatid`. O `event.Timestamp` de [`messages_update`](https://docs.uazapi.com/webhook/messages_update) usa segundos e não é tratado como uma nova mensagem.

## Configuração esperada na UAZAPI

O CHAIT envia `POST /webhook` com `enabled: true`, `events: [messages, messages_update, connection, chats]`, `excludeMessages: []`, `addUrlEvents: false` e `addUrlTypesMessages: false`. A URL aponta para a rota base sem segmento de evento. Filtro vazio permite que a UAZAPI entregue mensagens próprias, recebidas, individuais e de grupo; o CHAIT seleciona o que pode criar uma conversa. Conferir a configuração **efetiva** da instância com `GET /webhook`, que pode divergir do último pedido do CHAIT. `fromMeNo` ou `isGroupNo` em `excludeMessages` excluiriam justamente mensagens recebidas ou individuais. Não imprimir a URL completa: ela contém o segredo da rota. Referências: [configuração](https://docs.uazapi.com/docs/integrations-webhooks) e [consulta da configuração](https://docs.uazapi.com/endpoint/get/webhook).

## Procedimento para uma mensagem nova

1. Aplicar `2026_10_17_000003_track_uazapi_inbox_decisions`, reiniciar o Horizon e anotar o instante UTC imediatamente antes do teste. Esta migração acrescenta apenas `decision` e `delivery_count`; registros antigos terão `decision = NULL`.
2. Executar `php artisan chat:inspect-webhook 1`. O comando faz `GET /webhook` na instância e imprime somente `enabled`, `url_matches_expected`, eventos, filtros e opções de URL; não imprime URL, token nem payload. Confirmar `url_matches_expected: true`, `messages` em `events` e ausência de filtros que excluam recebidas individuais. Consultar `GET /webhook/errors` somente em ambiente restrito; não divulgar payload nem URL. A [documentação dos erros](https://docs.uazapi.com/endpoint/get/webhook~errors) informa que a captura é volátil.
3. Registrar as contagens abaixo, enviar **uma** mensagem individual de outro número depois da ativação e repetir as mesmas consultas. Não usar mensagem própria nem grupo. Esperar o worker concluir antes de comparar.

```sql
SELECT id, workspace_id, status, activated_at, chat_last_webhook_at
FROM uazapi_connections WHERE id = 1;

SELECT id, event_type, status, decision, delivery_count,
       payload IS NOT NULL AS payload_retained,
       created_at, dispatched_at, updated_at
FROM uazapi_inbox
WHERE uazapi_connection_id = 1
ORDER BY id DESC LIMIT 20;

SELECT count(*) AS threads_total,
       count(*) FILTER (WHERE last_inbound_at >= TIMESTAMP '2026-10-17 20:30:31') AS threads_com_entrada_apos_ativacao,
       max(last_inbound_at) AS ultima_entrada
FROM threads
WHERE workspace_id = 1 AND uazapi_connection_id = 1;
```

Usar o `id` do novo recibo, se houver, para verificar a projeção sem selecionar JID, telefone ou conteúdo:

```sql
SELECT i.id AS receipt_id, i.event_type, i.status, i.decision,
       i.delivery_count, t.id AS thread_id, t.last_inbound_at
FROM uazapi_inbox i
LEFT JOIN threads t
  ON t.uazapi_connection_id = i.uazapi_connection_id
 AND t.external_chat_id = i.chat_id
WHERE i.id = 123; -- substituir pelo ID do recibo
```

`inbound_projected` com `thread_id` confirma processamento e gravação. `outbound_unknown`, `before_activation`, `invalid_timestamp`, `invalid_direction`, `group_message`, `unsupported_jid`, `invalid_phone`, `not_activated` e `unprojectable` explicam ausência de projeção. `chat_unknown` e `receipt_unknown` são eventos que não descobrem contato. `pending` aponta para fila/dispatch; `failed` aponta para log `Inbound processing failed.` pelo ID. Uma entrega HTTP 200 sem novo ID pode ser deduplicação **somente se** `delivery_count` de um recibo existente aumentar; caso contrário, confirmar pela resposta JSON `receipt_id` e pela configuração/entrega da UAZAPI que o POST atingiu essa rota Laravel.

Após `inbound_projected`, consultar `GET /workspaces/1/conversations` com usuário autorizado e filtros vazios (`assignment=all&status=all&search=`). A lista é local nos dois modos. No modo atual, a tela consulta o endpoint a cada 30 segundos; com tempo real, Reverb atualiza a tela, mas recarregar consulta o mesmo endpoint. Administradores e agentes com `can_view_unassigned` veem threads sem responsável; outros agentes veem somente as atribuídas a si. Filtros por atribuição, status, tag e busca também podem ocultar uma thread válida. Não registrar o JSON inteiro da resposta, pois contém dados de contato e prévia.

## Evidência ainda necessária para a causa em produção

O código antigo não registrava a direção, o timestamp ou a classe do JID do único `messages` já processado às 20:33:09. Se seu `payload` estiver nulo, para classificá-lo seria preciso uma cópia privada já existente do payload na UAZAPI ou seus metadados sanitizados. O teste novo com `decision` fornece a classificação sem reter conteúdo. Se não surgir nenhum `messages` novo, comparar a entrega da UAZAPI para a instância e URL corretas com os recibos do CHAIT; HTTP 200 agregado do Nginx pode pertencer aos outros tipos de evento.
