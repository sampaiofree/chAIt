# Assistente IA para Criação e Edição de Fluxos

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Projeto:** chAIt — SaaS de atendimento e automações  
> **Versão:** 1.0  
> **Data:** 2026-10-14  
> **Estado:** MVP implementado em Laravel, Vue 3, Inertia e Vue Flow.  
> **Referências:** [Editor](editor-de-fluxos.md), [decisões](../planejamento/decisoes.md), [deploy](../operacao/deploy.md).

## 1. Objetivo e decisões vigentes

O assistente conversa com a equipe, faz um briefing curto, apresenta um plano e monta ou edita o fluxo aberto depois da aprovação no botão **Construir**. Ele constrói automações; o bloco IA de atendimento continua sendo um recurso independente que conversa com leads durante a execução.

As decisões abaixo substituem as propostas anteriores de salvar automaticamente cada lote no rascunho definitivo e oferecer desfazer:

| Decisão       | Comportamento                                                                                                           |
| ------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Interface     | Janela flutuante no desktop, tela completa no celular; recolher não interrompe a IA nem descarta alterações.            |
| Aprovação     | Nenhuma mutação da IA antes da aprovação de um plano persistido pelo botão Construir.                                   |
| Prévia        | Sessão temporária compartilhada por toda a equipe que pode editar o fluxo.                                              |
| Edição manual | Sincronizada após 500 ms sem nova edição; pausada durante uma execução da IA.                                           |
| Salvar        | Substitui o rascunho salvo, com validação e controle de revisão. Não publica.                                           |
| Descartar     | Confirmação explícita; descarta a prévia de toda a equipe, interrompe a IA e volta ao último rascunho salvo.            |
| Desfazer      | Fora do escopo. Não há snapshots ou restauração de versões antigas do rascunho para desfazer.                           |
| Recuperação   | A prévia permanece após recarga/fechamento acidental; digitações ainda não sincronizadas têm recuperação local por aba. |
| Histórico     | Compartilhado por fluxo e workspace; preservado após salvar, descartar e publicar.                                      |
| Credencial    | Chave OpenAI própria do workspace, criptografada, usada somente no servidor.                                            |
| Modelos       | Automático usa `gpt-6.1-sol`; seleção manual entre modelos compatíveis acessíveis pela chave.                           |
| Permissões    | Editar fluxos para ler histórico/sessão; Editar fluxos + Utilizar Assistente IA para chamar ou retomar a IA.            |
| Publicação    | Sempre manual pelo mecanismo normal do editor; versões publicadas não são alteradas pela IA.                            |
| Custos        | Controle mensal de consumo e cobrança fora do MVP.                                                                      |

## 2. Jornada e colaboração

1. Abrir o editor recupera a prévia compartilhada existente, sem sobrescrever o rascunho salvo.
2. **Assistente IA** abre a conversa; o usuário descreve o resultado desejado.
3. A IA pergunta somente o necessário, uma pergunta por vez quando houver ambiguidade relevante.
4. `propose_plan` persiste briefing e plano, contendo objetivo, mensagens, caminhos, ações, recursos e limitações. A apresentação do plano encerra o turno e invalida a autorização de planos anteriores. A conversa guarda o ID exato do plano aprovado vigente.
5. **Construir** aprova o plano no servidor e inicia a execução em fila.
6. Cada lote validado atualiza somente a prévia. O canvas recebe o estado confirmado pelo servidor progressivamente.
7. Durante a IA, a equipe acompanha e pode **Parar**. Edição manual e Salvar ficam pausados.
8. Depois, ajustes pedidos na conversa podem editar diretamente a prévia aprovada. **Salvar** substitui o rascunho; **Descartar alterações** invalida a prévia e exige novo plano antes de reconstruir.
9. **Publicar** continua uma ação manual separada, depois de salvar ou descartar a sessão temporária.

A navegação controlada pela aplicação confirma e executa o descarte antes de sair. O aviso nativo de fechar/recarregar não informa ao JavaScript qual escolha foi feita; nesses casos, a recuperação é preservada. Recolher a janela do assistente não equivale a sair do editor.

Duas abas/usuários compartilham a sessão, mas cada atualização exige a revisão esperada. Em conflito, o editor conserva alterações locais e oferece carregar a prévia vigente ou reaplicar os campos alterados localmente. Reaplicar exige confirmação e preserva alterações remotas em outros campos. Um salvamento ou descarte invalida a geração anterior; respostas tardias de jobs não recriam a sessão.

## 3. Escopo exato da IA

Permitido:

- Catálogo real dos nós, campos aceitos, handles, limites e regras do editor.
- Grafo temporário corrente do fluxo aberto, nome e revisão, filtrados antes do envio.
- Definições de etiquetas e campos personalizados do workspace ativo: IDs, nomes e tipos.
- Briefing, plano aprovado e contexto recente da conversa desse fluxo.
- Alterar somente nós, posições, configurações e conexões da sessão temporária.

Proibido:

- Consultar outros fluxos, membros, sequências, contatos, valores de campos de leads, conversas WhatsApp ou conteúdo de mídias.
- Criar etiquetas/campos/recursos externos, inventar IDs ou URLs de mídias.
- Receber credenciais, usar SQL, navegar, executar código arbitrário ou ferramentas genéricas.
- Salvar o rascunho definitivo, publicar, ativar gatilhos ou disparar automações/mensagens reais.

IDs de atendentes, destinos de outros fluxos, sequências e mídias precisam de seleção manual. Referências existentes são preservadas nas partes não alteradas e ocultadas no contexto da IA. Tags/campos indicados pela IA são conferidos no workspace atual. O backend fixa o escopo pela sessão autenticada, recurso aberto e geração corrente, nunca por IDs escolhidos pelo modelo.

O catálogo inclui `start`, `content`, `wait_reply`, `operations`, `jump_flow`, `condition`, `ai` e `menu`. Condição combina etiquetas (`has`/`not_has`) e campos de texto (`equals`/`contains`) em `checks`, com `condition_operator=all|any`. O catálogo expõe os campos padrão (`display_name`, `email`, `cpf`), e a ferramenta consulta definições dos personalizados de texto. Não há acesso a valores de contatos ou interpretação de intenção. Consulte [Bloco Condição](bloco-condicao.md). A saída human do bloco IA é terminal. Start é reutilizado e não pode ser criado, removido ou reconfigurado pela IA.

## 4. Contratos e persistência

O guia operacional enviado em todas as chamadas ao modelo fica em `resources/prompts/flow-assistant.md`. Ele explica comportamento dos blocos, exceções, conexões, planejamento, construção e retomada, incluindo a distinção entre `Não` da Condição, prazo vencido de Aguardar Resposta e tratamento interno de erros do Menu. `get_editor_capabilities` complementa o guia com `usage` por bloco, `meaning` por saída (inclusive dinâmicas) e `graph_rules`. Os campos e limites continuam derivados do catálogo atual. Saídas terminais precisam ser explicitadas no plano; validade estrutural não comprova que o pedido está completo. Esta orientação não libera ciclos nem cria repetição automática de ferramentas.

O grafo continua em `schema_version: 2`. `FlowCapabilities` centraliza regras, campos por tipo e handles usados pelo validador, editor e ferramentas. O rascunho incompleto pode ser salvo; a publicação manual executa a validação completa já existente.

| Persistência                     | Finalidade                                                                                        |
| -------------------------------- | ------------------------------------------------------------------------------------------------- |
| `flows.graph` / `flows.revision` | Rascunho salvo, substituído somente por Salvar.                                                   |
| `flow_draft_sessions`            | Uma prévia por fluxo; grafo, nome, geração, revisão própria e revisão-base.                       |
| `flow_ai_conversations`          | Histórico, briefing, estado e preferência de modelo do fluxo.                                     |
| `flow_ai_messages`               | Mensagens, planos, aprovação, resultados e marcação de alterações temporárias/salvas/descartadas. |
| `flow_ai_runs`                   | Solicitante, modelo, geração, status, ciclos, erro e horários.                                    |
| `flow_ai_operations`             | Resultado idempotente por execução e chamada; sem snapshots para desfazer.                        |

Histórico e sessões não têm expiração automática no MVP. Exclusão do fluxo/workspace remove os registros relacionados por cascata. A versão publicada mantém seu ciclo de vida independente.

Endpoints sob `/workspaces/{workspace}/flows/{flow}`:

| Método e caminho                      | Finalidade                                                                                  |
| ------------------------------------- | ------------------------------------------------------------------------------------------- |
| `GET assistant`                       | Estado, histórico recente, catálogo, sessão e execução; `before` pagina mensagens antigas.  |
| `GET assistant/models`                | Interseção entre catálogo compatível e modelos da chave, cache de 5 minutos por credencial. |
| `POST assistant/messages`             | Conversar ou solicitar ajuste.                                                              |
| `POST assistant/plans/{plan}/approve` | Aprovar plano vigente e iniciar construção.                                                 |
| `POST assistant/runs/{run}/stop`      | Interromper execução; disponível a editores mesmo sem permissão de chamar IA.               |
| `POST assistant/runs/{run}/resume`    | Nova execução baseada na prévia confirmada; exige permissão de IA.                          |
| `PUT draft-session`                   | Sincronizar nome/grafo, exigindo `base_revision`, `generation` e `draft_revision`.          |
| `DELETE draft-session`                | Descartar a geração confirmada pela equipe.                                                 |
| `PUT` no fluxo existente              | Salvar; exige geração/revisão temporária quando houver prévia ativa.                        |

Conflitos retornam 409, validação 422 e autorização 403. Escritas usam transação e bloqueio do fluxo. Salvamentos antigos sem dados da sessão são rejeitados enquanto houver prévia; renomeação pela lista e publicação também não ignoram a sessão ativa.

Ferramentas: `get_editor_capabilities`, `get_current_flow`, `list_tags`, `list_custom_fields`, `validate_flow`, `propose_plan` e, somente depois da aprovação, `apply_flow_patch`. Lotes aceitam `add_node`, `update_node`, `move_node`, `remove_node`, `connect` e `disconnect`. IDs reais são gerados pelo servidor; referências temporárias são resolvidas e devolvidas nos resultados. Remover nó remove conexões associadas no mesmo lote. `update_node` aceita `clear_fields` para limpar campos opcionais; valores null em `data` mantêm o campo existente.

## 5. OpenAI, execução e falhas

O desenvolvimento e os testes usam PostgreSQL, com bancos separados para aplicação e fixtures. As transações do assistente usam bloqueio de linha e até três tentativas para falhas transitórias reconhecidas pelo Laravel; chamadas externas ficam fora dessas transações, inclusive na retomada, e o despacho acontece depois da confirmação. O contexto intermediário fica em memória, sem gravar `provider_items` em cada ciclo. Falhas SQL não expõem consultas nem bindings: a interface mostra uma mensagem segura e o log registra somente fluxo, execução e SQLSTATE. Consulte [configuração, transferência e testes](../desenvolvimento/postgresql.md).

Responses API com Function Calling estrito, `parallel_tool_calls: false`, `store: false` e histórico mantido pela aplicação; não usar conversa remota persistente. Reasoning items e resultados de funções são encaminhados na continuação do turno.

O cliente do assistente é independente do cliente dos blocos IA de atendimento. A mesma credencial atende ambos. Configurar a chave não exige acesso à transcrição; publicar blocos de atendimento continua verificando os modelos/transcrição de que eles precisam.

`ProcessFlowAssistant` usa a fila `ai` quando `CHAIT_REALTIME_ENABLED=true` e `default` quando desabilitado. O Horizon seleciona os supervisores conforme a mesma configuração; ver [operação do tempo real](../operacao/chat-realtime.md). Há uma execução ativa por fluxo. Permissões, workspace ativo, geração e credencial são revalidados antes de novas chamadas e operações. Alterar/remover a chave interrompe a continuação com a credencial anterior.

Limites configuráveis em `config/flow-assistant.php`:

- Mensagem: 8.000 caracteres.
- Lote: 20 operações.
- Execução: 30 ciclos, 150 segundos; timeout do job de 180 segundos.
- Contexto: briefing consolidado, plano aprovado, últimas 40 mensagens e grafo filtrado; teto de 250.000 caracteres.
- Resposta: até 12.000 tokens, raciocínio low.
- Entrada: 12 solicitações por minuto por usuário/workspace.

O editor consulta progresso a cada segundo durante execução e a cada cinco segundos quando ocioso. Ao detectar execução estagnada por mais de 210 segundos, o servidor permite retomada. Reconexão não depende de conexão SSE ou broadcast.

Um lote inválido é totalmente revertido; lotes anteriores permanecem na prévia. Repetir a mesma chamada devolve seu resultado e não duplica blocos/auditoria; trocar o conteúdo de uma chamada já usada é conflito. Retomar cria outra execução, lê o grafo corrente e não reaplica automaticamente escritas antigas. Falhas da chave/modelo/provedor mantêm prévia e histórico, sem troca silenciosa de modelo.

## 6. Verificação e limites

Testes PHP cobrem aprovação, lotes, salvamento, descarte, idempotência, permissões, isolamento, recursos, retomada e falhas de integração. Playwright cobre recuperação após recarga, compartilhamento entre abas, aprovação/progresso, pausa, teclado e temas claro/escuro em 390, 768, 1024, 1280 e 1440 px.

Não incluir no MVP: desfazer, custos mensais, simulador, publicação automática, recursos externos criados pela IA, acesso a leads/conversas ou capacidades de nós não implementadas.

## Histórico

- **v0.1 — 2026-10-14:** especificação inicial, com arquitetura proposta.
- **v1.0 — 2026-10-14:** MVP e contratos documentados; decisões de prévia temporária compartilhada, salvar/descartar explícitos, janela flutuante, recuperação e ausência de desfazer substituem as propostas anteriores.

## Menu — 14/10/2026

O catálogo e schemas estritos incluem `menu`, campos configuráveis e saídas dinâmicas `option:{id}` e `continue`. Renomear, reordenar e trocar formato preservam IDs e conexões. Remover opção ligada exige `disconnect` no mesmo lote; erro reverte o lote inteiro. `validate_flow` identifica pendências por campo, inclusive homologação de Botões/Lista. Mantêm-se aprovação, prévia compartilhada, revisões, idempotência, Salvar e Publicar manuais. Contrato em [Bloco Menu](bloco-menu.md).

## Conteúdo com Botão — 15/10/2026

O catálogo, schemas estritos, leitura do grafo e patches incluem `items[].kind=button`, sua mensagem e `button.kind/label/url/copy_text`. A IA pode criar, editar, trocar tipo, reordenar e remover o elemento preservando IDs e os demais itens. Identidade de Resposta é gerada pelo servidor. `validate_flow` aponta campos pendentes e homologação por tipo/conexão; rascunhos incompletos continuam permitidos. Conteúdo mantém somente `next` e não espera cliques. Use Aguardar Resposta para coletar ou Menu para escolhas com caminhos. [Contrato completo](bloco-conteudo-elemento-botao.md).

## Importação JSON — 15/10/2026

A importação é uma ação determinística do editor, disponível a quem pode gerenciar fluxos, sem permissão de chamar IA ou chave configurada. Fica bloqueada durante execução em fila/andamento do assistente. Após parar ou concluir, a confirmação substitui e salva o rascunho, encerra a geração da prévia, invalida o plano anterior e preserva o histórico. Uma nova construção exige novo plano; gerações antigas não podem ser retomadas nem recriar a prévia. A sincronização do navegador fica suspensa durante o diálogo e respostas de consultas anteriores à importação são ignoradas. Ver [contrato e operação](importacao-exportacao-fluxos.md).

## Campos do contato — 15/10/2026

`get_editor_capabilities.contact_fields` apresenta os campos padrão e IDs/nomes atuais dos personalizados do workspace, sem valores dos contatos. Schemas estritos aceitam string ou ContactTemplate v1 nos campos habilitados. Patches podem inserir, editar, remover e reordenar partes preservando IDs dos itens; referências inventadas/externas revertem o lote. A exclusão de campo verifica também prévias compartilhadas e rascunhos sob bloqueio workspace → fluxo. `validate_flow` mostra pendências de homologação por campo; não autoriza publicação automática. Ver [contrato completo](campos-do-contato-fluxos.md) e `resources/prompts/flow-assistant.md`.
