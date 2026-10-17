# Bloco Conteúdo — Elemento Botão

> **Liberação em 17/10/2026:** por decisão do responsável, publicação e execução estão liberadas em todas as conexões, inclusive produção, sem perfis ou variáveis de homologação. A validação real em produção será feita pelo responsável; esta liberação não acrescenta evidências de homologação. Os ensaios anteriores permanecem como histórico.

> O registro de 15/10/2026 abaixo é histórico; a homologação local posterior está no fim desta página.

> **Estado:** URL, Resposta e Copiar liberados em todas as conexões; validação de produção a cargo do responsável.
> **Referências:** [briefing aprovado](../planejamento/implementar/bloco-conteudo-elemento-botao.md), [Editor](editor-de-fluxos.md), [UAZAPI](../integracoes/uazapi.md), [Assistente construtor](assistente-ia-fluxos.md).

## Comportamento e editor

Conteúdo aceita zero ou um elemento Botão, misturado aos itens sequenciais existentes. O elemento envia uma mensagem com exatamente uma ação: URL, Resposta ou Copiar. Continua a sequência e a saída única **Próximo passo**, sem esperar interação. Não há Ligação, opções múltiplas, fallback numérico ou novas saídas. Para caminhos por escolha, usar Menu.

O painel oferece Botão junto de Texto, Mídia e Atraso. A adição fica desabilitada enquanto houver um Botão; remoção permite nova inclusão. O card pode ser movido como os demais. Campos de mensagem, tipo, rótulo e destino condicional têm validação próxima e prévia. O canvas identifica o botão mesmo depois dos dois itens resumidos. O ID interno não é editável.

A orientação de Resposta é: “O clique envia uma resposta do contato. Para aguardar essa resposta, utilize o bloco Aguardar Resposta. Para criar caminhos diferentes, utilize o bloco Menu.”

## Contrato e validação

O projeto mantém `schema_version: 2` e `nodes[].data.items[]`. O discriminador real é `kind`, substituindo o `type` ilustrativo do briefing:

```json
{
    "id": "item_estavel",
    "kind": "button",
    "text": "Quer conhecer nossos cursos?",
    "button": {
        "kind": "url",
        "label": "Ver cursos",
        "url": "https://exemplo.com/cursos"
    }
}
```

`button.kind` aceita `url`, `reply`, `copy`. URL usa `url`; Copiar usa `copy_text`; Resposta não exige destino fornecido pelo editor/IA. Identidade é derivada no servidor do item, nó, versão e execução; não há `reply_id` configurável no grafo. IDs existentes devem ser preservados ao editar e reordenar.

Rascunhos incompletos são aceitos. Mais de um Botão, propriedades desconhecidas de `button`, tipos não suportados e valores inseguros são rejeitados estruturalmente. Publicação exige mensagem, rótulo, destino do tipo. URL aceita HTTP/HTTPS sem espaços, controles ou `|`. Rótulo e cópia não aceitam controles nem `|`, para não introduzir outra ação no protocolo `choices`. A mensagem pode ter quebras de linha.

Os tetos técnicos da aplicação são 4.000 caracteres para mensagem/destino e 120 para rótulo. São limites da aplicação; os limites adicionais por conexão foram retirados.

## Transporte, entrada e concorrência

O executor utiliza `/send/menu`, `type: button`, `choices` de um item e `track_id`. Não envia o texto separadamente. A intenção e a correlação de Resposta ficam no contexto da execução antes da chamada externa. Estado incerto, HTTP 409/5xx, timeout, ausência de ID ou Queued/Pending levam à consulta por `track_id`, até o orçamento existente de 24 tentativas, com despacho após 5 segundos. Nunca há reenvio cego ou conversão para outro tipo. Rejeição definitiva registra a ocorrência e avança para o próximo item. Esgotamento da conciliação registra resultado desconhecido e continua sem reenviar.

A entrada reconhece somente evento interativo explícito com token reservado para a conexão/contato corretos e tipo `ButtonsResponseMessage`. O rótulo vem da versão imutável que enviou o botão, não do rascunho atual nem do texto arbitrário do webhook. A normalização atende palavras-chave, Aguardar Resposta e entrada da IA de atendimento; o Menu continua recebendo o evento original.

Atendimento humano e pausas mantêm prioridade; palavras-chave interruptivas precedem captura. Sem espera ativa, aplica-se o processamento normal. Clique tardio não reabre execução de origem. Duplicação é barrada pelos recibos existentes, inclusive entre capturas/execuções diferentes.

Para a continuação direta `Conteúdo → Aguardar Resposta`, o clique antecipado pode deixar uma referência operacional em `flow_executions.context`, sem corpo de mensagem. Itens restantes, inclusive atrasos, continuam normalmente. A ativação da espera seguinte consome a referência sob o mesmo bloqueio por contato; confirmação HTTP e avanço não sobrescrevem a referência. Uma espera já respondida ou expirada não aceita esse buffer. Cancelamento/encerramento limpa a referência antecipada e não reativa o fluxo.

Recibo de webhook e processamento são confirmados na mesma transação, com ordem de bloqueio por contato compatível com o executor. Falha reverte o recibo para permitir nova tentativa. O elemento não cria tabela ou histórico WhatsApp próprio; seu buffer guarda referências, não texto recebido. No modo de tempo real, a entrada geral do chat pode usar payload temporário criptografado conforme o [contrato operacional](../operacao/chat-realtime.md), sem corpos em jobs ou logs.

## Assistente construtor

Catálogo, schemas estritos, `get_current_flow`, `apply_flow_patch` e `validate_flow` incluem todos os campos configuráveis. A IA pode criar, alterar, trocar tipo, reordenar ou excluir o elemento e receber pendências por item/campo. Identificadores operacionais são gerados no servidor. Lotes inválidos revertem integralmente; aprovação, revisão, prévia compartilhada, permissões e publicação manual são preservadas. A IA não recebe autorização para enviar mensagens reais.

## Homologação e verificação

Os perfis por conexão e `config/flow-content-button.php` foram removidos. URL, Resposta e Copiar podem ser publicados e enviados sem habilitação adicional. Identificadores de Resposta continuam gerados pelo servidor e vinculados à execução, versão e item.

Para a validação operacional, um HTTP 200 isolado não comprova a ação. Conferir renderização e ação nos clientes WhatsApp relevantes; para Resposta, confirmar evento real, tipo e identificador no webhook público. Não presumir confirmação de clique URL/Copiar. A entrega de 15/10 ainda não havia feito essa homologação.

Comandos de verificação local:

- `php artisan test --compact`
- `APP_ENV=testing php tests/Concurrency/content-button-transitions.php`
- `CHAIT_BROWSER_PORT=8899 npx playwright test tests/Browser/content-button.spec.ts tests/Browser/menu.spec.ts --output /tmp/chait-content-button-browser-results`
- `npm run types:check`, `npm run lint:check`, `npm run format:check`, `npm run test:voice`, `npm run build`, `composer lint:check`.

Os cenários verificam três tipos, validação, compatibilidade, acesso da IA, idempotência, captura antecipada, timeout concorrente, atendimento humano, palavras-chave, versão enviada, falha transacional do webhook, transporte incerto, recuperação e responsividade. Dados e provedores são sintéticos, sem OpenAI paga ou WhatsApp real.

### Evidências locais — 15/10/2026

Passaram **493 testes PHP (2.416 assertions)**, **13 testes de navegador** de Conteúdo/Menu/Assistente, **17 testes frontend** e **quatro disputas reais entre processos PostgreSQL**. Tipos, ESLint, Prettier, Pint e build passaram. Após o ajuste de contraste da prévia no tema escuro, o cenário visual foi repetido com contraste mínimo de 4,5:1 nos dois temas e cinco larguras. As 20 capturas de formulário/prévia foram preservadas em [evidências de verificação](../../artifacts/content-button-verificacao/resultado.json); capturas dos dois temas foram inspecionadas nas larguras previstas. Nenhum perfil foi habilitado e não houve envio real.

## Campos do contato

Mensagem, rótulo e destino de URL/Copiar aceitam strings ou ContactTemplate v1. Todo o elemento Resposta permanece sem chips. A troca para Resposta exige remover chips da mensagem e do rótulo. Os limites são revalidados após expansão. Ver [contrato, continuidade, exclusão protegida e homologação](campos-do-contato-fluxos.md).

## Homologação local com túnel — 16/10/2026

URL abriu o endereço esperado no iPhone; Copiar colocou o valor exato na área de transferência. Resposta preservou um ID de 60 caracteres no webhook público e uma execução real capturou o rótulo após `ButtonsResponseMessage`. Perfis restritos por tipo foram carregados somente do `.env` local e devem ser retirados ao encerrar os túneis. Nenhum perfil foi aplicado à produção. Veja [evidências e limites](../../artifacts/tunnel-homologacao/resultado.md).
