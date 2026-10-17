# Bloco Menu

> **Data:** 2026-10-14  
> **Estado:** editor, contrato, Assistente IA e executor implementados. Botões e Lista foram homologados somente na conexão local 1 em 16/10/2026; a produção permanece pendente.

## Comportamento aprovado

Um único bloco apresenta **Numérico**, **Botões** ou **Lista**, aguarda uma escolha e segue pela saída da opção. Há uma entrada `input`, saídas `option:{id}` e uma saída adicional `continue`, apresentada como **Continuar**. Prazo ou limite de respostas inválidas acionam somente Continuar. Uma saída sem conexão encerra o ramo normalmente.

Numérico é o formato inicial. Aceita exclusivamente o número anunciado após remover espaços externos: `2` é válido; `02`, `2.`, rótulos, frases e mídias não são. Botões e Lista exigem `buttonOrListid` correspondente ao token da espera atual; texto digitado não é clique. Seleções de menus antigos, duplicatas, mensagens próprias, reações e eventos técnicos não consomem tentativas.

A espera começa após confirmar o envio e tem padrão de **5 minutos**, ajustável de **1 minuto a 7 dias**. O limite começa em **3 respostas inválidas**, ajustável de **1 a 10**. Antes do limite, o sistema envia a mensagem de erro configurada e reapresenta as opções, sem renovar o prazo. Ao atingir o limite, segue por Continuar sem reenviar. A mensagem inicial é “Opção inválida. Escolha uma das opções abaixo.” e pode ser editada.

Lista inclui pergunta, opções e botão de abertura, inicialmente **Ver opções**. Seções, descrições por opção, rodapé, mídias, ações de URL, ligação, cópia e enquetes ficam fora desta entrega.

## Editor e contrato

O painel reutiliza componentes e temas existentes. Pergunta e opções têm destaque; espera e erros ficam em seção recolhida. Opções podem ser adicionadas, renomeadas, movidas e removidas. Remoção de opção conectada exige confirmação; remove apenas a conexão, preservando o destino. Renomeação, reordenação e troca de formato preservam IDs e ligações. O canvas mostra a pergunta resumida, formato, limites e todas as saídas identificadas.

O grafo mantém `schema_version: 2`, com tipo `menu`. Campos em `data`:

| Campo | Contrato |
|---|---|
| `menu_format` | `numeric`, `button` ou `list`. |
| `question` | Pergunta para o contato, até 4.000 caracteres. |
| `options` | Lista ordenada de `{id,label}`; ID estável de até 48 caracteres alfanuméricos, `_` e `-`, único no nó. |
| `invalid_reply_text` | Mensagem de erro, até 4.000 caracteres. |
| `timeout_seconds` | Inteiro de 60 a 604800; padrão 300. |
| `max_invalid_replies` | Inteiro de 1 a 10; padrão 3. |
| `list_button_text` | Texto de abertura da Lista, até 120 caracteres; padrão Ver opções. |

Rótulos têm até 120 caracteres, uma linha e não aceitam `|`, usado como separador do provedor. A mensagem numérica completa, incluindo instrução e enumeração, deve caber em 4.000 caracteres. São limites da aplicação; limites específicos interativos são medidos na homologação. Publicação exige pergunta, pelo menos uma opção preenchida, mensagem de erro, prazo e limite válidos. Lista também exige texto de abertura. Rascunhos incompletos podem ser salvos.

`FlowCapabilities::outputsFor()` resolve saídas reais por nó. O catálogo declara `dynamic_outputs` (`field: options`, `prefix: option:`), compartilhado pelo editor e pela IA. Não ligar pelo índice visual.

## Assistente IA do editor

`get_editor_capabilities`, `get_current_flow`, `validate_flow` e `apply_flow_patch` incluem Menu. A IA pode criar, editar, reordenar opções e conectar saídas na prévia compartilhada. IDs de opções são estáveis e validados; nós e conexões continuam recebendo IDs reais do servidor. Excluir opção ligada exige desconectar sua saída no mesmo lote; lote estruturalmente inválido é revertido.

São mantidos plano aprovado, revisões, idempotência, isolamento por workspace, Salvar e Publicar manuais. Diagnósticos identificam `node_id`, campo e mensagem. A IA de atendimento não recebe ferramenta de envio de Menu.

## Executor e persistência

`waiting_menu` mantém a reserva exclusiva por contato/conexão e bloqueia o avanço de Sequências. `flow_menu_waits` guarda execução, nó, token da espera, formato efetivo, erros, opção selecionada, transporte pendente e prazo. `flow_menu_receipts` guarda hashes dos IDs consumidos. Corpos recebidos não vão para banco, filas ou logs. Resposta que chega antes do retorno HTTP guarda somente opção interpretada ou erro, sendo consumida após confirmar o envio.

Seleção, expiração e limite disputam o mesmo bloqueio por contato/contexto/espera. Somente uma transição pode vencer. Palavra-chave interruptiva mantém prioridade; pausa ou atendimento humano cancela a espera e envios pendentes. Jobs atrasados não reativam a execução.

A intenção com `track_id` é gravada antes do transporte. Chamadas externas ficam fora das transações. Numérico usa `/send/text`; Botões e Lista usam `/send/menu`, com IDs exclusivos da espera em `choices`. A lista usa `listButton`. [Contrato UAZAPI](https://docs.uazapi.com/endpoint/post/send~menu).

Timeout, 409, 5xx, resposta sem identificação ou Queued/Pending levam à conciliação por `/message/find`, sem repetir o envio. Consultas respeitam intervalo de 5 segundos e orçamento de 24 consultas. Falha comprovada do menu interativo (400/415/422 ou status Failed/Canceled) permite uma tentativa Numérica; números só são aceitos depois da confirmação desse envio. Erros de autorização ou limite de requisições não disparam fallback. Falha definitiva termina a execução como failed, sem fingir erro do contato ou acionar Continuar.

`ExpireFlowMenu` agenda o vencimento. `menus:tick`, agendado a cada minuto, recupera transportes e expira esperas mesmo fora de Sequências. Redis/Horizon e scheduler precisam estar ativos.

## Liberação dos formatos interativos

Por padrão, `config/flow-menu.php` mantém `connections` vazio. Publicação de Botões/Lista é recusada, mas a configuração pode ser preservada no rascunho. A remoção da homologação também impede novos envios interativos.

Após testar uma conexão real, registrar seu ID, formato e limites comprovados:

```php
'connections' => [
    // ID real => [
    //     'button' => ['verified' => true, 'max_options' => LIMITE_MEDIDO, 'max_label_length' => LIMITE_MEDIDO],
    //     'list' => ['verified' => true, 'max_options' => LIMITE_MEDIDO, 'max_label_length' => LIMITE_MEDIDO],
    // ],
],
```

Não habilitar apenas porque `/send/menu` respondeu 200. Confirmar cliques inequívocos, distinção de texto igual ao rótulo, menus antigos, limites e renderização nos clientes relevantes. O webhook informa que campos condicionais variam; homologação real é obrigatória. [Eventos messages](https://docs.uazapi.com/webhook/messages).

## Verificação

- PHP: `php artisan test --compact tests/Feature/Chait/FlowMenuTest.php tests/Feature/Chait/FlowAssistantTest.php`.
- Concorrência real PostgreSQL: `APP_ENV=testing php tests/Concurrency/menu-transitions.php`, em schema temporário isolado de `chait_test`.
- Navegador: `CHAIT_BROWSER_PORT=8899 npx playwright test tests/Browser/menu.spec.ts --output /tmp/chait-menu-browser-results`.
- Tipos, lint, formatação e build pelos comandos existentes.

Os testes automatizados de 14/10 usaram dados sintéticos e respostas simuladas, sem OpenAI paga ou WhatsApp real. A homologação operacional requeria destinatário explicitamente autorizado e cliques no WhatsApp; o ensaio local posterior está registrado abaixo.

### Evidências locais — 14/10/2026

Passaram 453 testes PHP (2.290 assertions), 38 testes de navegador nas áreas afetadas, 17 testes frontend existentes, dois cenários com processos PostgreSQL concorrentes, tipos, lint, formatação e build. Os cinco testes de navegador do Menu cobrem conexões estáveis, remoção confirmada, configuração por teclado, temas em 390/768/1024/1280/1440 px, bloqueio de publicação interativa e criação contextual sem sobreposição. Capturas foram inspecionadas nos temas claro e escuro. Migration aplicada localmente; reinício gracioso dos workers solicitado por `queue:restart`. Transporte e apresentação no iPhone foram conferidos no teste real abaixo; o webhook ainda não foi homologado.


## Teste real autorizado — 14/10/2026

Dois menus foram enviados uma única vez ao número autorizado nesta conversa: Botões com 3 opções/20 caracteres por rótulo e Lista com 10 opções/24 caracteres. Ambos foram confirmados como `Delivered` por `track_id`. O usuário confirmou apresentação e funcionamento no **iPhone**. A consulta `/message/find` identificou seleção do botão 1 como `ButtonsResponseMessage` e da opção 10 como `ListResponseMessage`, com `buttonOrListid` exatamente correspondente aos IDs enviados. Não houve reenvio ou mudança nas automações/CRM do contato.

O receptor real está configurado para **chait.test** e não existe URL HTTPS pública para este projeto, conforme confirmado pelo usuário. Nenhum evento de seleção pôde ser capturado no webhook local. A evidência da API de consulta e do iPhone não substitui essa prova. **Botões e Lista permanecem bloqueados para publicação**, sem habilitar perfis. Evidências somente operacionais em [resultado.json](../../artifacts/menu-homologacao/resultado.json); não contém corpos recebidos ou credenciais.

O parser exige, além do ID inequívoco, o tipo `ButtonsResponseMessage` para Botões ou `ListResponseMessage` para Lista. Um texto comum com campo de seleção incoerente não seleciona opção; escolhas interativas não respondem ao modo Numérico. Ao disponibilizar URL pública, validar esses campos nos eventos reais e então registrar o perfil homologado, incluindo os clientes relevantes.

## Homologação local com túnel — 16/10/2026

O webhook público temporário recebeu cliques reais de três Botões e da décima opção da Lista, com tipos e IDs esperados. Uma mensagem comum com o mesmo rótulo chegou sem ID de seleção. Execuções reais de Botões e Lista escolheram o ramo `b` e enviaram a mensagem correspondente ao iPhone. Os perfis locais foram limitados a 3 opções/10 caracteres e 10 opções/10 caracteres, respectivamente. A configuração fica no `.env` local e deve ser retirada ao encerrar os túneis. A produção continua sem perfil. Veja [evidências e limitações](../../artifacts/tunnel-homologacao/resultado.md).
