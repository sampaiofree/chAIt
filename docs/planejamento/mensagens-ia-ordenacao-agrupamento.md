# Mensagens para IA — ordenação, agrupamento e concorrência

> **Data:** 17/11/2026
>
> **Status do diagnóstico original:** planejado em 17/11/2026. A implementação posterior reúne textos e ordena a preparação de mídias no bloco IA. O comportamento entregue está no [contrato do bloco IA](../modulos/bloco-ia.md). O roteiro abaixo registra também etapas de recuperação e capacidade mais amplas.
>
> **Base:** comparação do código local do Chait e do Facilitai, testes existentes do Chait e reprodução isolada com chamadas externas simuladas. Não representa homologação em produção.

## Objetivo

Evoluir gradualmente o recebimento de várias mensagens na mesma conversa, preservando a ordem até a IA, reunindo mensagens próximas e evitando perda ou repetição durante processamento e recuperação.

O Chait tem uma base mais segura para concorrência, mas ainda não supera o Facilitai em todos os aspectos. A recomendação é evoluir o Chait e aproveitar a ideia de agrupamento do Facilitai, corrigindo as limitações identificadas nessa implementação.

Este roteiro trata do bloco IA de atendimento, não do Assistente IA que edita fluxos. Sua ordem é interna a esta evolução e não redefine a prioridade geral do projeto. Documentá-lo não implementa funcionalidades nem aprova valores de configuração, migrations ou novos contratos de API.

## Diagnóstico atual

| Aspecto | Chait | Facilitai | Avaliação |
| --- | --- | --- | --- |
| Ordem na entrada | Eventos posteriores esperam eventos anteriores pendentes da mesma conexão/conversa. | Não foi encontrado controle equivalente no fluxo analisado. | Chait tem proteção explícita melhor. |
| Ordem entregue à IA | Há exclusividade por sessão, mas não uma seleção explícita da próxima entrada pela ordem de aceitação; mídias passam por preparação separada. | Textos entram no buffer pela execução dos jobs; mídias seguem fora do agrupamento. | Ambos precisam melhorar a garantia até o consumo. |
| Agrupamento | Cada entrada elegível pode gerar um turno separado. | Textos são reunidos após um período sem novas mensagens. | Facilitai tem vantagem funcional. |
| Chamadas simultâneas na mesma conversa | Trava por sessão durante o processamento; sessão ocupada provoca reagendamento. | Travas protegem acesso ao buffer, sem abranger toda a chamada à IA. | Chait tem proteção melhor contra sobreposição. |
| Duplicação | Caixa de entrada com identidade persistente e registro único por mensagem/sessão. | Deduplicação por cache e unicidade dos jobs até o início do processamento. | Chait tem proteção persistente mais forte; isso não comprova processamento exatamente uma vez em qualquer falha. |
| Capacidade | Configuração atual limita IA a um job por workspace por vez. | Há múltiplos workers de conversa na configuração do Horizon. | Limite global do Chait pode atrasar contatos independentes; quantidade de workers não garante ordem. |

Ordenação na tela, ordenação na entrada e ordenação no consumo da IA são garantias distintas. A interface do Chait ordena por timestamp, mas isso não determina qual mensagem chega primeiro à IA. A evolução deve preservar a ordem de aceitação local por conversa; não promete reconstruir a ordem original de envio de webhooks que já chegam invertidos do provedor.

### Problema reproduzido no Chait

1. Duas entradas da mesma sessão são criadas na ordem: imagem e texto.
2. O job da imagem agenda `PrepareAiInput` na fila de mídia e retorna.
3. O job do texto seguinte processa “Explique a imagem que enviei”.
4. A chamada simulada à OpenAI recebe o texto enquanto a imagem continua pendente.

A trava da sessão impede simultaneidade, mas não impede essa ultrapassagem. A reprodução usou a base `chait_test`, filas e HTTP simulados, com rollback dos dados. O roteiro foi executado de forma avulsa e não foi salvo como teste permanente; transformá-lo em regressão automatizada faz parte da etapa 1.

### Limitações observadas no Facilitai

- O período sem novas mensagens usa `Assistant::delay`, com padrão de 25 segundos. O parâmetro `debounceSeconds`, cujo padrão é 5, não é a janela principal nesse caminho.
- O buffer preserva somente os últimos dez textos por meio de `array_slice(..., -10)`. Entradas anteriores podem deixar de compor o envio.
- Apesar do nome `maxWaitSeconds`, a espera é calculada desde a última mensagem, sem um teto absoluto contado desde a primeira. Um envio contínuo pode adiar o processamento repetidamente.
- Durante a chamada à IA, novas mensagens são acrescentadas ao mesmo buffer. Ao detectar uma chegada posterior, a limpeza preserva o buffer inteiro, incluindo os textos já processados. Exemplo: envia `A + B`, recebe `C` durante a resposta e pode enviar `A + B + C` no próximo ciclo.
- `ShouldBeUniqueUntilProcessing` libera a unicidade ao começar a execução. Em conjunto com as travas curtas do buffer e o caminho direto das mídias, não garante exclusividade durante toda a chamada à IA.

Essas conclusões do Facilitai resultam da leitura do código; não foram reproduzidas em teste de concorrência ou carga nesse projeto.

## Referências e evidências

### Chait

| Referência | Evidência no código |
| --- | --- |
| [InboundEvents::accept](../../app/Services/Chat/InboundEvents.php) | Aceitação durável e deduplicação em `uazapi_inbox`. |
| [ProcessInboundEvent::handle](../../app/Jobs/Chat/ProcessInboundEvent.php) | Trava por conexão/conversa e espera por eventos anteriores pendentes. |
| [FlowInboundProcessor::handle](../../app/Services/Flows/FlowInboundProcessor.php) | Encaminhamento individual das mensagens elegíveis à sessão IA. |
| [ProcessAiTurn::handle](../../app/Jobs/ProcessAiTurn.php) e [PrepareAiInput::handle](../../app/Jobs/Chat/PrepareAiInput.php) | Separação da preparação de mídia, permitindo a ultrapassagem reproduzida. |
| [AiSessionService::process](../../app/Services/Flows/AiSessionService.php) | Trava da sessão, reagendamento, deduplicação por entrada e tratamento de envio pendente. |
| [WorkspaceCapacity](../../app/Queue/Middleware/WorkspaceCapacity.php) e [configuração de chat](../../config/chat.php) | Limites por workspace/categoria; atualmente `ai = 1` com chat ativo. |
| [mergeChatMessages](../../resources/js/lib/chatState.ts) | Mesclagem por identidade e ordenação visual por timestamp. |

### Facilitai

As referências abaixo são caminhos relativos ao repositório Facilitai, consultado como projeto irmão do Chait. A documentação do Chait não depende da presença dessa cópia local.

| Referência no Facilitai | Evidência no código |
| --- | --- |
| `app/Jobs/ProcessIncomingMessageJob.php` — `processMessage`, `handleDebounce`, `resolveMaxWaitSeconds` | Caminhos de texto/mídia, buffer de dez textos, janela de espera, envio e limpeza que pode preservar conteúdo anterior. |
| Mesmo arquivo — `uniqueId`, `uniqueFor` e `ShouldBeUniqueUntilProcessing` | Unicidade até o processamento, sem trava da conversa abrangendo a chamada completa. |
| `app/Services/IAOrchestratorService.php` e `app/Services/OpenAIOrchestratorService.php` | Caminho atual até a OpenAI, sem exclusividade da conversa nesse trecho. |
| `app/Jobs/UazapiJob.php` — `deduplicateEvent` | Deduplicação UAZAPI em cache com TTL de dez minutos. |

### Validação executada em 17/11/2026

| Verificação | Resultado e limite |
| --- | --- |
| `vendor/bin/pest tests/Feature/Chait/AiBlockTest.php tests/Feature/Chait/ChatRuntimeTest.php --compact` | 124 testes aprovados, 584 asserções, com ambiente de teste isolado. A aprovação não cobre automaticamente as lacunas deste roteiro. |
| `node --experimental-strip-types --test tests/Frontend/chat-state.test.mjs` | Cinco testes aprovados sobre mesclagem, estado e ordem visual. |
| Reprodução avulsa de imagem seguida de texto | Uma preparação de mídia pendente e somente o texto seguinte registrado em `ai_session_inputs` e entregue à OpenAI simulada. |
| Facilitai | Análise estática; sem execução de suíte, teste de carga ou homologação real nesta comparação. |

## Etapas de implementação futura

Cada etapa deve gerar uma entrega independente com implementação, teste de regressão e registro de homologação. Não marcar uma etapa inteira como concluída apenas porque seus testes locais passaram.

### Etapa 1 — Ordenação até a IA

Preservar a ordem de aceitação local das mensagens elegíveis de uma conversa, incluindo mídias em preparação. Manter a exclusividade por sessão e as proteções existentes de interrupção e expiração.

- [ ] Especificar o acompanhamento de entradas pendentes e o tratamento de mídia indisponível, expirada ou com falha definitiva, evitando bloqueio indefinido das entradas seguintes.
- [ ] Implementar a escolha da próxima entrada pela ordem de aceitação, sem depender da ordem de execução ou de aquisição de uma trava.
- [ ] Automatizar a regressão imagem seguida de texto, além de jobs executados em ordem invertida e mensagens duplicadas.
- [ ] Homologar a sequência com mídia lenta e registrar ambiente, resultado e referência da alteração.

**Aceite:** uma entrada posterior não ultrapassa outra anterior pendente da mesma sessão; falha terminal libera o avanço por uma regra explícita e observável, sem sumir silenciosamente com a mensagem.

### Etapa 2 — Agrupamento seguro

Reunir mensagens próximas sem misturar o lote já reservado para processamento com as novas entradas. Depende da ordenação da etapa 1.

- [ ] Definir janela de agrupamento, teto absoluto de espera, limites do lote, modalidades participantes e efeito sobre contagem de mensagens/inatividade.
- [ ] Implementar reserva do lote e acúmulo separado das entradas que chegam durante a resposta. Não copiar o buffer mutável compartilhado do Facilitai.
- [ ] Testar três textos próximos, mais de dez entradas, envio contínuo e chegada de `C` enquanto `A + B` estão em processamento.
- [ ] Homologar agrupamento e limites, registrando quais modalidades foram efetivamente validadas.

**Aceite:** cada entrada pertence ao lote correto; não há descarte silencioso, reenvio de textos do lote anterior nem espera renovada indefinidamente. As regras escolhidas para mídias preservam a ordem.

### Etapa 3 — Recuperação

Tornar rastreável a diferença entre trabalho pendente, reservado, concluído e resultado desconhecido, reutilizando as proteções existentes. Depende das entradas/lotes definidos nas etapas anteriores.

- [ ] Especificar recuperação de reserva abandonada, reinício de worker e resultado desconhecido da chamada à IA ou do envio ao WhatsApp.
- [ ] Implementar recuperação sem presumir que um registro de deduplicação significa conclusão e sem repetir cegamente chamadas ou envios incertos.
- [ ] Testar jobs duplicados, reinício após reserva e após chamada externa, interrupção humana e expiração durante o trabalho.
- [ ] Homologar recuperação com falhas simuladas, metadados observáveis e descarte de conteúdo conforme a política vigente.

**Aceite:** entradas pendentes continuam rastreáveis; resultados incertos recebem tratamento explícito; sessões encerradas não voltam a responder por causa de recuperação.

### Etapa 4 — Capacidade e liberação gradual

Medir espera e concorrência antes de alterar os limites por workspace. Depende das garantias de ordenação, agrupamento e recuperação anteriores.

- [ ] Definir métricas de espera/idade dos pendentes, tamanho dos lotes, preparação de mídia, falhas e resultados desconhecidos, sem conteúdo de mensagens.
- [ ] Medir contatos independentes no mesmo workspace e em workspaces diferentes; definir limites e mecanismo de ativação/reversão da evolução.
- [ ] Testar concorrência entre processos com Redis compartilhado, verificando exclusividade por sessão e progresso das conversas independentes.
- [ ] Liberar gradualmente em homologação e depois em produção, registrando métricas antes/depois e condições de reversão.

**Aceite:** conversas independentes progridem dentro dos limites definidos, sem sobreposição na mesma sessão. Reverter a ativação preserva o estado das entradas/lotes e não provoca reenvios automáticos de resultado incerto.

## Limites e decisões para cada etapa

- Preservar o [contrato do bloco IA](../modulos/bloco-ia.md), incluindo abertura gerada pelo prompt sem incorporar automaticamente a mensagem que disparou o fluxo.
- Preservar a [política de conteúdo temporário](../operacao/chat-realtime.md): payload criptografado, jobs por referência, descarte após consumo e retenção máxima vigente de 24 horas. Agrupamento e recuperação não autorizam histórico permanente, conteúdo em logs ou extensão silenciosa da retenção.
- Definir os parâmetros numéricos na especificação da respectiva etapa. Os 25 segundos e dez textos do Facilitai são fatos da comparação, não padrões propostos para o Chait.
- Definir campos, estados, índices, migrations e eventuais mudanças de API somente ao detalhar cada implementação. Não há alteração de interfaces públicas nesta entrega documental.
- Ao concluir uma etapa, atualizar este documento, o checklist e os contratos de módulo/operação afetados com data, referência ao código, testes e alcance da homologação.
