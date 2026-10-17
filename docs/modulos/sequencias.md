# Sequências — especificação funcional, interface e arquitetura

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Projeto:** chAIt — atendimento e automações via WhatsApp  
> **Versão:** 1.0 — 14/10/2026  
> **Status:** implementado e validado localmente; decisões complementares confirmadas pelo responsável durante o planejamento.  
> **Fonte canônica:** `docs/modulos/sequencias.md`.

## 1. Objetivo e limites

Sequências são programações ordenadas de etapas que iniciam fluxos de conversa publicados. Cada contato/conexão possui uma única participação vigente e um único fluxo ativo, incluindo esperas de resposta, IA e atrasos.

As etapas usam o executor central existente. Mensagens, condições, IA e atendimento humano continuam nos blocos dos fluxos. A implementação usa Laravel, Vue 3/Inertia, PostgreSQL em produção e Redis/Horizon. A tela é linear, sem canvas Vue Flow.

Um WhatsApp por workspace. Fora desta versão: recorrência, importação/inclusão em massa, API pública de inscrição, webhooks externos, campanhas, CTR, remoção, pausa ou reinício manual de participação.

## 2. Decisões funcionais APROVADAS

Estas são as regras que prevalecem sobre hipóteses anteriores da discussão.

| ID    | Regra aprovada                                                                                                                                                     | Consequência                                                                                                                                                                                                                      |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SQ-01 | **Um único fluxo de conversa ativo por lead/conexão WhatsApp** em todo o motor de automações.                                                                      | Sequência, palavra-chave, boas-vindas, resposta padrão e outros gatilhos não devem criar execuções paralelas conflitantes. Mantêm-se as exceções explícitas de interrupção já aprovadas para palavras-chave e atendimento humano. |
| SQ-02 | **A execução da sequência é sequencial.**                                                                                                                          | Só se programa/inicia a etapa seguinte depois que o fluxo da etapa anterior termina conforme a política de término válida.                                                                                                        |
| SQ-03 | **Intervalo relativo começa após o término do fluxo anterior**, não necessariamente na entrada do lead.                                                            | A primeira etapa usa como marco a entrada _quando não houver fluxo ativo_; se houver, espera seu encerramento.                                                                                                                    |
| SQ-04 | **A contagem relativa fica suspensa** enquanto outro fluxo ou atendimento humano estiver ativo, retomando do ponto restante depois.                                | Não perder tempo restante nem iniciar um fluxo por causa do vencimento enquanto o lead estiver ocupado.                                                                                                                           |
| SQ-05 | **Data/hora específica é fixa.** Se o horário vencer enquanto o lead estiver ocupado, a etapa espera a liberação.                                                  | Não recalcular a data configurada; um vencimento não autoriza concorrência de fluxos.                                                                                                                                             |
| SQ-06 | **Uma única sequência vigente por lead/conexão.**                                                                                                                  | Não existem duas participações ativas no mesmo contexto.                                                                                                                                                                          |
| SQ-07 | **Entrar em Sequência B substitui automaticamente Sequência A.**                                                                                                   | Encerra a participação na A e cancela suas futuras etapas/esperas.                                                                                                                                                                |
| SQ-08 | **A troca NÃO interrompe o fluxo que já começou.**                                                                                                                 | O fluxo atual da A (ou outro fluxo já ativo) termina normalmente; só depois começa a contagem/eligibilidade da primeira etapa da B. Esta regra **substitui** a decisão intermediária de interrupção imediata.                     |
| SQ-09 | **Nova tentativa de entrar na mesma sequência vigente é ignorada.**                                                                                                | Não reinicia contagem, etapa, fluxo nem inscrição; após terminar/sair, nova inscrição pode começar do início.                                                                                                                     |
| SQ-10 | **Duas formas de inclusão:** operação `Adicionar à sequência` no bloco **Operações** de um fluxo; e inclusão manual no cadastro de contato por usuário autorizado. | Ambas usam o mesmo serviço/backend e a mesma regra de substituição.                                                                                                                                                               |
| SQ-11 | **Ao incluir em sequência a partir de fluxo normal em execução**, o fluxo atual continua e termina antes do primeiro agendamento.                                  | Inscrição é feita imediatamente, início efetivo do relógio aguarda o término da execução atual.                                                                                                                                   |
| SQ-12 | **Disparos de sequência não são descartados pelo atendimento humano.**                                                                                             | Um vencimento fica aguardando; só pode prosseguir após finalizar o atendimento e respeitando as demais regras.                                                                                                                    |
| SQ-13 | **Não haverá uma fila geral de fluxos para Sequências.**                                                                                                           | Persistir a inscrição e **somente sua próxima etapa**; não colocar todas as etapas como disparos independentes em uma fila de execução.                                                                                           |

**Decisão substituída expressamente:** a primeira hipótese de calcular _todos os intervalos desde a entrada do lead_ foi abandonada. A regra final é a **SQ-02/SQ-03: intervalos vinculados ao fim da etapa anterior**.

### 2.1. Atenção à palavra “terminar”

Para a troca de sequências, a regra aprovada é preservar o fluxo corrente até ele terminar **sem cancelá-lo por causa da troca**. Isso **não revoga** interrupções provocadas por outras regras do sistema (ex.: palavra-chave interruptiva, abertura de atendimento humano, ações explícitas de encerramento). A política da sequência após cada um desses resultados está detalhada nas políticas fechadas abaixo; não fingir que `completed`, `interrupted` e `failed` são equivalentes.

---

## 3. Conceitos e relações

| Conceito                   | Significado                                                                                           |
| -------------------------- | ----------------------------------------------------------------------------------------------------- |
| **Sequência**              | Programação nomeada, pertencente a um workspace, composta por etapas ordenadas.                       |
| **Etapa**                  | Um agendamento (`intervalo` ou `data específica`) associado a **um fluxo publicado**.                 |
| **Inscrição/participação** | Vínculo de um lead/conexão a uma sequência, com posição, marco temporal e estado próprio.             |
| **Fluxo em execução**      | Execução do motor central de fluxos; no máximo uma ativa por lead/conexão.                            |
| **Próxima etapa**          | Única etapa ainda não iniciada que pode ter um vencimento/tempo restante ativo para aquela inscrição. |
| **Ocupado**                | Lead/conexão com execução de fluxo em andamento ou atendimento humano que impeça automações.          |
| **Término do fluxo**       | Evento emitido pelo motor central quando a execução deixa de estar ativa, acompanhado do **motivo**.  |

**Separação crucial:** uma inscrição na sequência pode ser encerrada por substituição enquanto o **fluxo que ela havia iniciado** continua ativo. A execução guarda sua origem histórica, mas **não tenta avançar** a sequência substituída quando terminar.

## 4. Exemplos de execução

### 4.1. Intervalos em série

| Evento                  | Data/hora ilustrativa | Comportamento                                        |
| ----------------------- | --------------------- | ---------------------------------------------------- |
| Lead entra na sequência | 14/10, 10:00          | Inicia contagem de 1 minuto se estiver livre.        |
| Etapa 1: fluxo A        | 14/10, 10:01          | Fluxo A começa.                                      |
| Fluxo A termina         | 14/10, 10:20          | **Só agora** inicia contagem da etapa 2: 2 horas.    |
| Etapa 2: fluxo B        | 14/10, 12:20          | Fluxo B começa, se lead permanecer disponível.       |
| Fluxo B termina         | 14/10, 12:35          | Inicia contagem da etapa 3: 1 dia.                   |
| Etapa 3: fluxo C        | 15/10, 12:35          | Inicia fluxo C, caso nenhum bloqueio tenha ocorrido. |

### 4.2. Pausa de uma contagem relativa

Exemplo independente: após um fluxo terminar às **10:00**, inicia-se a espera de **2 horas**. Às **10:30**, outro fluxo começa e mantém o lead ocupado por **20 minutos**. O contador fica com **1h30 restante**; ao liberar às **10:50**, retoma essa contagem. O próximo fluxo fica elegível às **12:20**, e não às 12:00.

Se o bloqueio acontecer quando o tempo já acabou, o restante é **zero**: a próxima etapa fica pronta, mas não executa até o lead estar livre.

### 4.3. Data específica

Etapa agendada para **20/10 às 09:00**. Se o lead está em atendimento humano até **11:00**, a data configurada **não muda**, mas a etapa só pode iniciar **depois das 11:00**, após a liberação do contato. Caso uma etapa anterior da mesma sequência ainda não tenha terminado, a etapa de data específica respeita também a **ordem sequencial**.

### 4.4. Troca de sequência

1. O lead está inscrito na **Sequência A**. Seu **fluxo A2** está ativo, inclusive se estiver no bloco `Aguardar Resposta`.
2. Um usuário, ou o bloco `Operações`, adiciona o lead à **Sequência B**.
3. Na mesma transação, a inscrição A deixa de ser vigente e suas **etapas futuras** são invalidadas; a inscrição B torna-se a única vigente.
4. **Fluxo A2 continua**, sem cancelamento decorrente dessa substituição.
5. Quando A2 terminar, o gerenciador de execução verifica a inscrição vigente (B) e libera o marco inicial para a primeira etapa de B. **Nunca continua a programação de A.**
6. Se B tiver intervalo de 1 minuto na primeira etapa, só então começa seu minuto. Se B tiver data específica já vencida, fica apta a iniciar após liberação, sem iniciar simultaneamente a A2.

### 4.5. Reentrada duplicada

Se B já é vigente para o lead/conexão e chega outro pedido de entrada em B, responder como **não alterado**. Preservar todos os tempos e estados. Uma reentrada após B ter sido finalizada é **uma nova participação**, não reutilização silenciosa do registro anterior.

---

## 5. Regra global: somente um fluxo ativo

### 5.1. Contrato do gerenciador central

- O **motor de fluxos** é a autoridade sobre qual execução está ativa para `(workspace, conexão, lead)`.
- **Fluxos das Sequências não rodam em executor separado**; recebem origem `sequence` e referências de inscrição/etapa.
- A operação de **reservar/iniciar** fluxo precisa ser atômica: duas workers ou webhooks simultâneos não conseguem iniciar dois fluxos ativos.
- Bloqueios humanos devem ser considerados na mesma decisão atômica.
- Uma execução já iniciada pode estar aguardando mensagem, atraso ou tool/IA e **continua ocupando a vaga**.
- A regra não elimina **interrupção deliberada** de fluxo por palavra-chave configurada para isso nem a transição lógica do bloco `Ir para Fluxo`: ambos seguem os contratos do editor e precisam ser coordenados com a reserva da execução.

### 5.2. Sem fila geral

O sistema precisa de **estado durável da única próxima etapa**, mas não precisa de fila de execuções de todas as etapas. A inscrição pode estar:

1. **Aguardando o fim do fluxo ativo** no momento da entrada;
2. **Contando intervalo** da próxima etapa;
3. **Aguardando uma data específica**;
4. **Suspensa/bloqueada**, preservando saldo do intervalo ou mantendo a data fixa;
5. **Executando fluxo da etapa**;
6. **Concluída** ou **substituída**.

A representação exata (enum ou estado + campos) é decisão de engenharia. **Redis/Horizon pode executar um job para a próxima etapa**, mas a fonte da verdade sobre elegibilidade precisa estar no banco e o job deve validar o estado atual antes de iniciar qualquer execução. Um vencimento não cria uma segunda execução ativa.

### 5.3. Distinção entre bloqueios

| Origem do bloqueio                                 | Intervalo relativo                                                | Data específica                                            |
| -------------------------------------------------- | ----------------------------------------------------------------- | ---------------------------------------------------------- |
| Outro fluxo está ativo antes da primeira etapa     | Não inicia o relógio da primeira etapa antes do término do fluxo. | Preserva data configurada; não dispara até ficar livre.    |
| Outro fluxo inicia enquanto intervalo já conta     | Congela tempo restante; retoma quando terminar.                   | Preserva data; dispara quando elegível e livre.            |
| Atendimento humano impede automação                | Congela tempo restante; retoma após atendimento finalizado.       | Preserva data; se venceu, aguarda a liberação.             |
| Fluxo de uma etapa da própria sequência está ativo | Não inicia prazo da etapa seguinte até terminar o atual.          | Não avança etapas enquanto o fluxo anterior estiver ativo. |

**Não confundir:** atendimento humano pode cancelar um fluxo ativo pelas regras do multiatendimento. Isso não é o mesmo que substituir a inscrição A pela B; o resultado desse cancelamento deve ser comunicado ao módulo Sequências para decidir o próximo estado.

---

## 6. Entrada e autorização

- **Operações:** `add_to_sequence`, com `sequence_id`, usa a saída normal. A chave de inclusão combina execução, bloco e posição da operação. O fluxo corrente segue; o primeiro prazo aguarda sua liberação.
- **Contato:** painel Sequência, ação Adicionar à sequência e modal com destino publicado/ativo e aviso de substituição. O UUID da solicitação permanece igual numa repetição da mesma tentativa.
- Criar, editar, publicar, ativar e incluir exige `canManageFlows`: dono, administrador ou membro com permissão explícita. Inclusão manual e leitura da participação também exigem acesso à conversa correspondente.
- Inscrições simultâneas são serializadas; a última inclusão aplicada torna-se vigente. Replays de uma solicitação antiga retornam seu resultado original sem restaurar a inscrição substituída.
- Publicar/ativar exige referências válidas. Desativar bloqueia somente novas entradas; inscritos preservam seus prazos e versões.

## 7. Temporização e recuperação

- Intervalo configurado em segundos inteiros, entre **60 e 31.536.000**; formulário em minutos, horas e dias.
- O relógio só começa quando não há impedimentos. Ao bloquear, congela o saldo; ao liberar todos os motivos, calcula novo vencimento com esse saldo. Saldo zero continua pendente até haver disponibilidade.
- Data específica representa um instante único. Configuração com fuso IANA explícito, preenchido com `America/Sao_Paulo` na criação de novas sequências, independentemente do fuso do workspace; sequências existentes preservam o fuso salvo e o seletor permite alterá-lo; persistência em UTC. A data não muda com bloqueios ou atraso de etapas anteriores. Datas vencidas executam quando forem a próxima etapa e o contato estiver livre.
- Scheduler de um minuto, acrescido do tempo da fila. O navegador não controla os disparos.
- `sequences:tick` reconcilia participações e encaminha somente a próxima etapa para `automations`. Jobs revalidam participação, geração, posição, prazo e disponibilidade sob a trava do contato.
- Términos duráveis permitem recuperar uma notificação perdida. Execuções próprias estagnadas podem recuperar jobs de execução e expiração de resposta/IA. Atrasos de conteúdo guardam `resume_at` para não avançar antes do horário ao recuperar jobs.
- Antes do envio, persistir intenção e identificador único. Uma execução recuperada consulta o envio existente, sem repeti-lo cegamente. A confirmação usa o identificador na conexão, sem exigir o chat original: a UAZAPI pode normalizar o número brasileiro. Eventos guardam apenas identificadores e estado do provedor.

## 8. Estados da participação

| Estado                 | Significado                                                                            |
| ---------------------- | -------------------------------------------------------------------------------------- |
| `waiting_current_flow` | Primeira/próxima etapa aguarda liberação do fluxo corrente; relógio ainda não começou. |
| `waiting_time`         | Próxima etapa aguarda intervalo ou data.                                               |
| `blocked`              | Impedimento humano/manual/outro fluxo; saldo ou data preservados.                      |
| `ready`                | Etapa venceu e pode disputar a reserva exclusiva.                                      |
| `running_step`         | Fluxo da etapa ou sua cadeia de destinos ainda está ativo.                             |
| `completed`            | Todas as etapas encerradas conforme a política terminal.                               |
| `replaced`             | Outra inscrição substituiu esta; nenhuma nova etapa pode iniciar.                      |
| `failed`               | A própria etapa teve falha definitiva; nenhuma próxima etapa inicia.                   |

Mudanças de geração invalidam jobs antigos. Uma inscrição substituída conserva o vínculo histórico com o fluxo que continua, mas seu término não avança a inscrição antiga.

## 9. Arquitetura implementada

### Persistência

| Tabela                         | Responsabilidade                                                                           |
| ------------------------------ | ------------------------------------------------------------------------------------------ |
| `automation_contexts`          | Linha única por workspace/conexão/contato, com execução ativa e inscrição vigente.         |
| `sequences`                    | Nome, rascunho JSON, revisão, ativação e versão publicada.                                 |
| `sequence_versions`            | Nome, fuso e autoria imutáveis da publicação.                                              |
| `sequence_steps`               | Etapas ordenadas da versão, programação e versão do fluxo.                                 |
| `sequence_version_flows`       | Versões fixadas dos fluxos, incluindo destinos encadeados.                                 |
| `sequence_enrollments`         | Participação, próxima etapa, saldo, vencimento, bloqueios, geração e resultado.            |
| `sequence_enrollment_requests` | Idempotência das inclusões.                                                                |
| `flow_executions`              | Executor existente; origem da inscrição/etapa, continuação entre fluxos e motivo terminal. |
| `flow_events` / `audit_logs`   | Metadados operacionais de execução, inscrição e mudanças de estado.                        |

Não há novos contatos/conversas duplicados nem histórico completo local de mensagens. No modo de tempo real, prévias e conteúdo temporário seguem o [contrato do chat](../operacao/chat-realtime.md). O JSON dos fluxos conserva seus textos de configuração; mensagens recebidas/enviadas como histórico ficam no provedor.

### Serviços e concorrência

- `FlowExecutionManager`: reserva/término/transferência. Ordem de travas começa pelo contato, seguida do contexto e dos registros de execução/participação. Inicialização adota execução legada única; duplicidades legadas exigem revisão explícita, sem escolher silenciosamente uma vencedora.
- `SequenceDefinitionService`: valida rascunho, publicação imutável, versões transitivas e dependências.
- `SequenceEnrollmentService`: inclusão, reentrada ignorada, substituição e auditoria.
- `SequenceScheduler`: reconciliação, pausa/retomada, elegibilidade e solicitação ao executor.
- `AutomationContextChanged`: notificação síncrona dentro da transação; se a transição falhar, o estado inteiro é revertido. Banco e reconciliação continuam sendo a fonte da verdade.
- `ProcessSequenceStep`: job da próxima etapa, sem conteúdo de mensagens. Chamadas ao provedor acontecem fora das transações de reserva.

### Rotas autenticadas

| Método     | Rota após `/workspaces/{workspace}`       | Uso                                            |
| ---------- | ----------------------------------------- | ---------------------------------------------- |
| GET / POST | `/sequences`                              | Listar / criar rascunho.                       |
| GET / PUT  | `/sequences/{sequence}`                   | Editor / salvar rascunho completo com revisão. |
| POST       | `/sequences/{sequence}/publish`           | Publicar com revisão.                          |
| POST       | `/sequences/{sequence}/deactivate`        | Bloquear novas entradas.                       |
| POST       | `/sequences/{sequence}/activate`          | Liberar entradas da versão publicada.          |
| GET / POST | `/contacts/{contact}/sequence-enrollment` | Consultar / incluir pelo mesmo serviço.        |

PUT e publicação usam revisão e retornam `409` quando outra aba alterou o rascunho. Inclusão manual recebe `sequence_id` e `idempotency_key` UUID; retorno `enrolled` ou `already_enrolled`. Workspace/conexão/contato e todas as referências são validados no backend.

## 10. Interface implementada

- Menu **Automação**, abas **Palavras-chave | Sequências**. Fluxos mantém seu módulo; a rota antiga de gatilhos continua válida.
- Lista com busca, filtros Todas/Ativas/Rascunhos/Inativas, etapas e contagem real de participações vigentes.
- Editor vertical com nome e seletor de fuso alinhados pelo início dos controles, ajuda/erros abaixo e disposição em uma coluna até 640 px; busca dos fluxos publicados e etapas; adicionar, excluir e mover no rascunho. Salvar rascunho, publicar, desativar e reativar com efeitos explícitos.
- Publicação não altera inscritos anteriores. Erros junto aos campos; rascunho incompleto permitido. Confirmação ao sair com alterações ou excluir etapa; ações duplicadas bloqueadas durante envio.
- Painel do contato mostra programação, etapa, situação, saldo/data, motivos de bloqueio e última transição. Consulta atualizada periodicamente; inclusão por modal comum.
- Participações recentes no editor respeitam a visibilidade das conversas. Temas claro/escuro e campos empilhados no celular, sem rolagem horizontal da página.

## 11. Validação de configuração

Nome obrigatório, até 120 caracteres; até 100 etapas. Publicação exige pelo menos uma etapa completa, fuso válido e fluxos publicados/ativos do mesmo workspace. Versões de etapas e cadeias de saltos são fixadas nessa publicação. Ciclos e cadeias acima do limite do executor são recusados.

Fluxos referenciados por sequência ativa ou participação vigente não podem ser desativados. Exclusão de fluxos publicados/com histórico já é bloqueada pelo módulo Fluxos; referências imutáveis de Sequências também são preservadas. Reativar sequência exige que suas dependências estejam ativas.

## 12. Critérios de aceite para desenvolvimento e QA

> Critérios implementados e cobertos por testes de domínio, integração HTTP e concorrência. A homologação real valida dois envios, sem representar todos os cenários abaixo no provedor.

| ID     | Cenário                                                                         | Resultado esperado                                                                                                                                                                                          |
| ------ | ------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SEQ-01 | Lead livre entra em sequência cuja primeira etapa tem `Após 1 minuto`.          | Aguarda 1 minuto e inicia primeiro fluxo se continuar livre.                                                                                                                                                |
| SEQ-02 | Etapa A inicia às 10:01 e termina às 10:20; etapa B tem `Após 2 horas`.         | B fica elegível às 12:20, sem bloqueios.                                                                                                                                                                    |
| SEQ-03 | Fluxo A está aguardando resposta por 2 dias.                                    | B não inicia contagem até A terminar.                                                                                                                                                                       |
| SEQ-04 | Durante contagem de 2 horas, outro fluxo ocupa o lead por 20 minutos.           | Congela e depois retoma saldo; não perde nem reinicia 2 horas.                                                                                                                                              |
| SEQ-05 | Intervalo vence durante atendimento humano.                                     | Não dispara; mantém elegibilidade/saldo e executa quando puder após finalizar atendimento.                                                                                                                  |
| SEQ-06 | Etapa de data específica vence durante atendimento.                             | Mantém data fixa; executa quando lead estiver livre e etapa for a próxima.                                                                                                                                  |
| SEQ-07 | Lead em sequência A entra em B enquanto fluxo de A está ativo.                  | A encerra inscrição e cancela próximos passos; fluxo atual segue; B aguarda seu término.                                                                                                                    |
| SEQ-08 | Fluxo da A termina depois de A ter sido substituída por B.                      | Nenhuma nova etapa da A é disparada; primeira etapa da B é liberada.                                                                                                                                        |
| SEQ-09 | Bloco `Operações` inclui lead em sequência enquanto fluxo normal executa.       | O fluxo atual continua; a primeira etapa da sequência aguarda.                                                                                                                                              |
| SEQ-10 | Usuário autorizado adiciona contato à sequência pelo cadastro.                  | Mesmo serviço de inscrição, mesma regra de substituição e auditoria.                                                                                                                                        |
| SEQ-11 | Tenta incluir o lead na sequência em que já está.                               | `already_enrolled`, sem reset de etapa, relógio ou fluxo.                                                                                                                                                   |
| SEQ-12 | Lead termina sequência e é incluído novamente nela.                             | Nova inscrição começando pela primeira etapa.                                                                                                                                                               |
| SEQ-13 | Dois vencimentos/requests tentam iniciar fluxo no mesmo lead.                   | No máximo uma execução ativa é criada.                                                                                                                                                                      |
| SEQ-14 | Job antigo da sequência A roda após substituição pela B.                        | Não dispara nenhuma etapa/mensagem da A.                                                                                                                                                                    |
| SEQ-15 | Evento de término de fluxo chega duas vezes.                                    | Próxima etapa é criada/liberada **uma única vez**.                                                                                                                                                          |
| SEQ-16 | Usuário tenta incluir contato ou fluxo de outro workspace.                      | Operação recusada no backend.                                                                                                                                                                               |
| SEQ-17 | Lead está numa espera de resposta de um fluxo.                                  | A execução ocupa a vaga; sequência não começa fluxo em paralelo.                                                                                                                                            |
| SEQ-18 | Horário vence e o navegador está fechado.                                       | Scheduler/backend continuam controlando o próximo passo.                                                                                                                                                    |
| SEQ-19 | A inscrição B é criada, mas A ainda tem envio WhatsApp já aceito pelo provedor. | Sistema não promete retirar mensagem já enviada; nenhuma **nova etapa** da inscrição substituída é autorizada. O fluxo já iniciado pode continuar enviando até terminar ou sofrer outra interrupção válida. |
| SEQ-20 | Última etapa termina com conclusão válida.                                      | Inscrição é encerrada e não gera próximo disparo.                                                                                                                                                           |

---

## 13. Políticas complementares aprovadas

| Tema                                      | Decisão                                                                                     |
| ----------------------------------------- | ------------------------------------------------------------------------------------------- |
| Conclusão natural                         | Avança; última etapa conclui a participação.                                                |
| Palavra-chave interruptiva                | Encerra etapa com motivo; próximo prazo aguarda o novo fluxo e outros bloqueios.            |
| Atendimento humano / pausa manual         | Encerra etapa com motivo; próxima etapa aguarda liberação de todos os bloqueios.            |
| Última etapa interrompida                 | Conclui participação registrando o motivo, sem confundir interrupção com sucesso do fluxo.  |
| Falha definitiva da própria etapa         | Encerra participação com erro. Não reinicia nem reenvia automaticamente.                    |
| Término de fluxo de inscrição substituída | Não avança a inscrição antiga; libera a nova quando o contexto estiver disponível.          |
| Ir para Fluxo                             | Etapa termina apenas no fim da cadeia. Reserva e referências são transferidas atomicamente. |
| Edição / republicação                     | Novas inscrições usam nova versão; existentes preservam a anterior.                         |
| Disputa de gatilhos                       | Primeira reserva transacional vence; palavra-chave interruptiva conserva sua exceção.       |
| Atendimento aguardando atendente          | Bloqueia desde a abertura humana, inclusive `open`.                                         |
| Saída/reinício/recorrência/CTR            | Fora do MVP.                                                                                |

## 14. Verificação e operação

Verificação final: `composer ci:check` passou, com 247 testes PHP (1.371 verificações), 14 testes frontend, tipos, lint e formatação. Build e 26 cenários de navegador passaram.

Testes de domínio e HTTP em `tests/Feature/Chait/SequenceTest.php`, reserva central em `FlowExecutionManagerTest.php`, concorrência em `tests/Concurrency/sequences.php`, navegador em `tests/Browser/sequences.spec.ts` e homologação explícita em `tests/Live/sequences.php`.

Concorrência validada com processos independentes em PostgreSQL descartável: dois inícios, duas inscrições diferentes, dois jobs de vencimento e palavra-chave contra sequência. Layout inspecionado em 390, 768, 1024, 1280 e 1440 px, nos dois temas.

Homologação UAZAPI de 14/10/2026: dois fluxos reais, primeiro com data específica e segundo com intervalo de um minuto após o término; participação concluída. Identificada e corrigida a consulta de confirmação quando o provedor normaliza o número. O envio inicial foi reconciliado pelo identificador, sem reenvio. Artefatos de homologação permanecem inativos, com metadados disponíveis para revisão. Esta validação não afirma testes de todos os cenários no provedor.

Configurar cron do scheduler e Horizon conforme [Deploy](../operacao/deploy.md). Para diagnóstico, consultar participação, contexto exclusivo, execução e eventos correlacionados; não usar histórico de mensagens como estado da automação.

## 15. Referências e histórico

- [Editor de fluxos](editor-de-fluxos.md), [chat e multiatendimento](chat-multiatendimento.md), [interface do chat](../interfaces/chat.md), [UAZAPI](../integracoes/uazapi.md), [decisões](../planejamento/decisoes.md).
- v0.1 — 14/10/2026: briefing com regras SQ-01 a SQ-13 e pendências.
- v1.0 — 14/10/2026: políticas complementares aprovadas, módulo implementado, testes e homologação real; SEQ-19 alinhado à preservação do fluxo corrente.

## Integração com Menu — 14/10/2026

`waiting_menu` mantém a reserva exclusiva do fluxo. Nenhuma etapa seguinte inicia durante espera ou envio pendente do Menu. Seleção ou Continuar seguem o ramo; só o término do fluxo libera a próxima etapa. Falha técnica do Menu segue a política de falha definitiva da participação. A recuperação de esperas também ocorre por `menus:tick`, independente de inscrição em Sequências.
