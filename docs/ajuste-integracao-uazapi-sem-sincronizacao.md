# CHAIT — Ajuste da integração WhatsApp: operação por novas mensagens

> **Tipo:** especificação de mudança para desenvolvimento (documento independente)
> **Data:** 17/10/2026
> **Status:** implementado localmente; comportamento da instância UAZAPI contratada ainda sujeito a homologação
> **Prioridade:** substituir a lógica de importação/sincronização de listas, preservando o chat e os fluxos já definidos.

## 1. Objetivo

O CHAIT **não deve importar nem sincronizar listas de contatos, chats ou mensagens históricas** quando um WhatsApp for conectado. O sistema passa a conhecer novos participantes de conversa a partir de **novas mensagens recebidas via webhook após a ativação da conexão no CHAIT**.

A listagem de conversas será montada a partir dos **registros operacionais locais** do CHAIT, e não de uma listagem geral de chats da UAZAPI.

**Atenção:** a decisão **não proíbe** consultar a UAZAPI para **exibir mensagens de uma conversa individual já conhecida pelo CHAIT**, quando ela for aberta pelo usuário. Essa consulta é sob demanda e não deve descobrir/importar outros chats.

## 2. Comportamento aprovado

### 2.1. Primeira conexão

1. O administrador conecta e ativa seu WhatsApp no workspace.
2. O CHAIT configura o recebimento de eventos de mensagens novas.
3. **Nenhuma importação inicial** de contatos, grupos, chats ou histórico é executada.
4. Em uma instalação/workspace realmente novo, sem conversas locais, a lista do chat aparece **vazia**, com um estado visual apropriado.
5. Quando chega uma **nova mensagem recebida** após a ativação, o backend identifica a conexão e o workspace, localiza ou cria o contato local e cria/atualiza a referência operacional da conversa.
6. A conversa passa a aparecer na listagem conforme as permissões e filtros de atendimento existentes.
7. Ao abrir a conversa, a aplicação pode buscar **somente as mensagens dessa conversa** na UAZAPI, por meio do backend/adaptador.

**Importante:** receber uma mensagem **não significa abrir automaticamente atendimento humano**. Permanecem as regras existentes de automação, gatilhos, fila e atribuição. Não criar status humano `Aberto` por simples recebimento.

### 2.2. Mensagens posteriores e atualização da interface

- Para um contato já conhecido, uma nova mensagem recebida atualiza sua referência operacional local, sem criar contato duplicado.
- A lista deve utilizar **consultas ao banco local**, sujeitas ao workspace e às permissões; mensagens novas podem atualizar a interface em tempo real.
- Quando uma conversa estiver aberta, os eventos recebidos podem atualizá-la imediatamente; ao reabri-la ou recarregar a página, consultar mensagens daquela conversa sob demanda, sem varredura geral.
- Não persistir no banco Laravel o corpo integral nem o histórico bruto das mensagens WhatsApp. Permanecem permitidos os **metadados mínimos** necessários para identificar mensagem/conexão, ordenar a lista, deduplicar eventos e manter estado operacional, com a exceção aprovada de prévia textual de até 200 caracteres para a lista.
- Notas internas, campos CRM, atribuições e auditoria continuam persistidos localmente, como já definido.

### 2.3. Reconexão e reinício

- Uma **reconexão do mesmo número/mesma conexão lógica** não deve importar chats antigos nem apagar automaticamente os contatos/conversas locais existentes.
- O CHAIT continua processando **novas mensagens recebidas** após o restabelecimento.
- Não fazer recuperação retrospectiva das mensagens que possam ter chegado durante a indisponibilidade.
- Uma instância e seu número pertencem a um único workspace e permanecem vinculados; troca de número ou instância exige um fluxo específico, fora deste ajuste. **Não limpar nem reaproveitar identidades por suposição**.

### 2.4. Ambiente já implantado

A expressão **“lista começa vazia”** aplica-se ao **workspace sem conversas registradas**. Ao implantar a mudança em um workspace que já tem contatos e registros operacionais, **não excluir automaticamente dados existentes**. A alteração deve interromper importações futuras e fazer com que a lista seja alimentada por eventos novos. Qualquer limpeza retroativa exige autorização específica.

## 3. O que remover ou desativar

- Importação inicial de contatos/chats ao parear/conectar WhatsApp.
- Rotinas de sincronização periódica de listas de chats e contatos da UAZAPI.
- Paginação/varredura de todos os chats para alimentar a listagem do CHAIT.
- Sincronização ou recuperação automática de mensagens anteriores à ativação.
- Gatilhos de importação por reconexão, reinício, abertura da tela ou atualização da página.

**Não remover** o adaptador/endpoint necessário para consultar o histórico **de uma conversa individual existente**, nem os recursos de envio de mensagem, tratamento de anexos e processamento normal dos webhooks.

## 4. Fluxo técnico esperado

```text
Conexão WhatsApp ativada
    └── configurar/validar webhook (sem importação inicial)

Nova mensagem RECEBIDA após ativação
    └── webhook UAZAPI
         └── validar origem, conexão e workspace
              └── deduplicar evento
                   └── identificar contato naquele workspace/conexão
                        └── criar/atualizar contato e conversa OPERACIONAL
                             ├── atualizar lista de conversas (dados locais)
                             └── aplicar regras já existentes de fluxos/atendimento

Usuário abre uma conversa já existente no CHAIT
    └── backend consulta mensagens somente daquele chat na UAZAPI
         └── apresentar histórico que o provedor ainda disponibilizar
```

### Cuidados mínimos de implementação

1. **Isolamento:** toda leitura, criação e atualização deve ser vinculada ao workspace e à conexão correta.
2. **Idempotência:** webhook duplicado não deve duplicar contato, conversa ou disparo de fluxo. Garantir unicidade/controle transacional para eventos concorrentes.
3. **Corte temporal:** registrar quando a conexão foi **ativada no CHAIT**; não tratar eventos de sincronização histórica como mensagens novas. Validar campos/timestamps reais da UAZAPI, pois o horário de recebimento do webhook isoladamente não prova que a mensagem foi criada depois da ativação.
4. **Sentido da mensagem:** distinguir mensagens recebidas do contato de mensagens enviadas pelo próprio número/sistema; envio/eco de mensagem não deve, sozinho, criar novo contato ou iniciar fluxo.
5. **Tipo de conversa:** validar o identificador e o formato dos eventos (incluindo grupos, status, eventos administrativos e tipos especiais) para evitar criação de contatos espúrios. O escopo de grupos não é ampliado por esta mudança.
6. **Fila de processamento:** manter recepção rápida do webhook e processamento assíncrono conforme arquitetura existente; não assumir garantia de entrega em falhas.
7. **Consulta pontual:** ao abrir um chat, limitar a busca ao identificador externo daquela conversa, respeitar paginação, tratamento de erros e limites do provedor, sem varrer contatos.
8. **Ausência de histórico:** caso a UAZAPI não disponibilize mensagens anteriores, mostrar estado de histórico indisponível em vez de iniciar importação ou exibir promessa de histórico completo.
9. **Sem exclusões automáticas:** rollout e reconexão não removem cadastros, notas, atribuições ou eventos operacionais locais.

**Decisão de escopo:** os itens acima são salvaguardas normais do processamento. **Não autorizam** construir mecanismo especial de busca retroativa, replay ou reconciliação por intervalo de indisponibilidade.

## 5. Fora do escopo deste ajuste

- Recuperação de webhooks perdidos, consulta periódica de erros e busca retrospectiva por intervalo de falha (**adiados expressamente**).
- Sincronização manual ou agendada de contatos, listas de chats ou histórico completo.
- Garantia de recebimento de 100% das mensagens em períodos de falha.
- Mudança nas regras de boas-vindas, palavra-chave, resposta padrão, espera por resposta ou atendimento humano.
- Mudança na política aprovada de não armazenar conteúdo/histórico WhatsApp no banco Laravel.
- Exclusão retroativa de dados previamente registrados.

## 6. Critérios de aceite

- [ ] Conectar um número em workspace novo resulta em **lista vazia**, mesmo que o WhatsApp já possua muitos chats antigos.
- [ ] Não há chamada de listagem geral de chats/contatos nem tarefa de importação no pareamento.
- [ ] Chegada de nova mensagem recebida após ativação cria contato e conversa local no workspace correto.
- [ ] Uma segunda mensagem do mesmo contato atualiza a conversa, sem duplicação.
- [ ] Duplicação ou concorrência de webhooks não duplica conversas nem inicia fluxos repetidos.
- [ ] Abrir uma conversa local consulta, quando necessário, **apenas as mensagens daquele chat** no provedor.
- [ ] Atualizar a página ou reiniciar a aplicação não executa importação geral.
- [ ] Reconectar a mesma conexão preserva registros operacionais existentes e não importa chats antigos.
- [ ] Não são criados contatos apenas por eventos de mensagens enviadas pelo próprio número, nem por eventos históricos/administrativos.
- [ ] Contatos e conversas continuam submetidos às permissões, filtros e isolamento entre workspaces.
- [ ] A simples mensagem recebida não abre atendimento humano sem gatilho apropriado.
- [ ] Falhas de consulta de mensagens exibem indisponibilidade, sem varredura/sincronização como alternativa.
- [ ] Nenhuma rotina nova de recuperação de webhooks perdidos ou de mensagens antigas é implementada.

## 7. Referências e documentos afetados

Este documento complementa/substitui apenas os trechos conflitantes da documentação anterior. **Não altera os arquivos originais automaticamente.** O desenvolvedor deve atualizar o repositório após integrar a mudança:

| Documento | Ajuste necessário |
|---|---|
| `docs/modulos/chat-multiatendimento.md` | Separar lista local alimentada por webhooks da consulta pontual de mensagens; revisar escopo, fluxo de entrada e critérios de aceite. |
| `docs/integracoes/uazapi.md` | Registrar que `findChats`/listagem geral não é usado para popular a aplicação; `findMessages` permanece autorizado apenas por chat conhecido. |
| `docs/interfaces/chat.md` | Estado vazio inicial e listagem local; abertura do chat com consulta pontual e tratamento de histórico indisponível. |
| `docs/modulos/editor-de-fluxos.md` | Reafirmar que primeiro contato é a primeira mensagem nova elegível por lead/conexão, sem importar retrospectivamente conversas. |
| `docs/decisoes.md` | Registrar decisão de não sincronização e adiamento da recuperação de webhooks. |
| `docs/README.md` | Atualizar resumo arquitetural, se necessário. |

**Referências da UAZAPI para verificar na versão contratada:**
- Webhooks: https://docs.uazapi.com/docs/integrations-webhooks
- Consulta pontual de mensagens: https://docs.uazapi.com/reference/findMessages.md
- Consulta geral de chats (não usar para alimentar a lista): https://docs.uazapi.com/reference/findChats.md

## 8. Instrução objetiva ao desenvolvedor

> **Substitua a descoberta/importação/sincronização de chats e contatos da UAZAPI por cadastro incremental baseado exclusivamente em novas mensagens recebidas via webhook após a ativação da conexão no CHAIT. Faça a listagem de conversas a partir do banco operacional local. Preserve a consulta pontual de mensagens da UAZAPI quando o usuário abrir uma conversa conhecida. Não armazene o histórico nem o corpo integral das mensagens no Laravel; preserve apenas a prévia textual aprovada de até 200 caracteres e os dados operacionais necessários. Não implemente, neste ajuste, recuperação de webhooks perdidos ou sincronização retrospectiva. Preserve todos os controles existentes de workspace, atendimento humano, automação e dados já cadastrados.**

---

**Registro da decisão:** aprovada em 17/10/2026. Recuperação de falhas foi discutida e explicitamente adiada.
