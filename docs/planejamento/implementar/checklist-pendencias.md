# Checklist de implementação e homologação

> **Atualizado em:** 2026-11-21
>
> **Base:** revisão geral estática de 17/10/2026, sem execução de testes naquela revisão. Em 17/11/2026, inclusão pontual das etapas de mensagens para IA; evidências de testes locais estão no documento específico. Em 21/11/2026, atualização pontual de permissões e validação local; os demais itens não foram reavaliados.
> **Status:** acompanhamento de pendências; não define prioridade nem aprova novos recursos.

## Checklist de recursos

- [x] **Módulo de Leads:** listagem, busca, filtros, cadastro manual e ficha própria implementados; estado confirmado no documento de Leads de 17/11 e no código em 21/11/2026. Os contatos já existem e são utilizados pelo chat. Ver [briefing de Leads](../../modulos/leads.md).
- [x] **Transferência para outro atendente — implementada, conferida no código em 17/10/2026:** administrador pode escolher membro ativo como responsável; há também Assumir para mim. O item estava pendente na análise anterior. Referências: `UazapiChatController::assignTo`, serviço `HumanAssignment` e controles de responsável no dashboard. Ver [contrato atual](../../modulos/chat-multiatendimento.md#52-transferência-explícita). Esta conferência foi estática, sem novos testes operacionais.
- [x] **Visibilidade das conversas e permissões de Leads:** controles Próprias/Não atribuídas/Todas concluídos em 21/11/2026, com migração local, testes de backend e navegador; visualizar/editar Leads e criar etiquetas/campos já estavam implementados. Ver [contrato do Chat](../../modulos/chat-multiatendimento.md#4-acesso-e-visibilidade-das-conversas).
- [ ] **Permissão específica de transferência para terceiros:** evolução futura; nesta etapa escolher outro responsável continua exclusivo de administradores.
- [ ] **Notificações operacionais:** avisar a equipe sobre falhas ou resultados desconhecidos dos fluxos. Hoje o editor permite consultar ocorrências. Ver [proposta de notificações](../notificacoes-operacionais-fluxos.md).
- [ ] **Integração oficial com a Meta:** implementar conexão via Cloud API e Embedded Signup. A integração operacional atual usa UAZAPI. Ver [roteiro Meta](../../integracoes/meta-tech-provider.md).
- [ ] **Simulador de fluxos:** permitir testar uma conversa dentro do sistema sem enviar mensagens reais pelo WhatsApp.
- [ ] **Distribuição automática de atendimentos:** distribuir conversas entre atendentes, por exemplo, por rodízio.
- [ ] **Aplicação instalável como PWA:** oferecer instalação da aplicação web; a interface responsiva já existe.
- [ ] **Notificações push:** avisar pelo navegador/celular, com regras e permissões próprias.
- [ ] **Editar notas internas:** permitir alterar uma nota existente, após definição de autorização e auditoria.
- [ ] **Excluir notas internas:** permitir remover uma nota existente, após definição de autorização e auditoria.
- [ ] **Desfazer alterações do Assistente IA de Fluxos:** oferecer recuperação de alterações anteriores; atualmente Salvar/Descartar atuam sobre a prévia compartilhada, sem desfazer por etapas.
- [ ] **Painel de custos da IA:** acompanhar consumo e custos mensais; a chave OpenAI própria do workspace já existe.

Alguns itens são propostas ou exclusões deliberadas do escopo inicial. Sua presença neste checklist não significa aprovação para desenvolvimento. Consultar os documentos responsáveis e fechar as decisões necessárias antes de implementar. Referências adicionais: [Interface mobile](../../interfaces/mobile.md), [Assistente IA de Fluxos](../../modulos/assistente-ia-fluxos.md) e [Editor](../../modulos/editor-de-fluxos.md).

## Evolução das mensagens para IA

Planejamento de 17/11/2026, a partir da comparação Chait/Facilitai. A sequência abaixo é interna a esta evolução e não altera a prioridade geral do projeto. Cada etapa inclui implementação, testes e homologação, detalhados no [roteiro de mensagens para IA](../mensagens-ia-ordenacao-agrupamento.md).

- [ ] **Etapa 1 — Ordenação até a IA:** preservar a ordem de aceitação por conversa, incluindo mídias pendentes e tratamento explícito de falhas; cobrir a ultrapassagem reproduzida por teste permanente.
- [ ] **Etapa 2 — Agrupamento seguro:** definir janela, teto de espera e limites; separar o lote em processamento das novas entradas, sem descarte silencioso ou repetição de textos anteriores. Depende da etapa 1.
- [ ] **Etapa 3 — Recuperação:** tratar reservas abandonadas, jobs duplicados e reinício de worker, mantendo rastreabilidade e sem repetir cegamente chamadas ou envios incertos. Depende das etapas anteriores.
- [ ] **Etapa 4 — Capacidade e liberação gradual:** medir espera e concorrência entre conversas, validar exclusividade por sessão e definir ativação/reversão sem perder pendências. Depende das garantias das etapas anteriores.

## Recursos implementados que aguardam homologação ou liberação

Estes itens já têm código; os checks abaixo acompanham a validação operacional, não uma implementação do zero.

- [ ] **Chat em tempo real:** validar infraestrutura, ativação e operação real do caminho controlado por configuração. Ver [guia operacional](../../operacao/chat-realtime.md).
- [ ] **Menu com Botões/Lista:** liberado em todas as conexões em 17/10/2026; responsável validará seleções pelo webhook público em produção. Há registros locais de cliques e roteamento no iPhone. Ver [Bloco Menu](../../modulos/bloco-menu.md).
- [ ] **Elemento Botão do Conteúdo:** liberado em todas as conexões em 17/10/2026; responsável validará renderização e ação em produção, incluindo o webhook de Resposta. Ver [contrato do elemento](../../modulos/bloco-conteudo-elemento-botao.md).
- [ ] **Chips de campos do contato:** liberados em todas as conexões em 17/10/2026; responsável validará o conteúdo recebido em produção, considerando a alteração conhecida de padrões nativos em texto/legenda. Ver [contrato dos campos](../../modulos/campos-do-contato-fluxos.md).

## Como manter o checklist

- Marcar um recurso como concluído somente com implementação e verificação correspondentes; registrar data e referência ao código, teste ou evidência.
- Para homologação, registrar o recurso, conexão/ambiente e cenários efetivamente validados, sem incluir credenciais ou dados pessoais.
- Atualizar também o documento responsável quando o comportamento mudar.
- Os itens de produto ainda não aprovados permanecem propostas, mesmo quando listados aqui. O inventário descreve os módulos documentados e as áreas encontradas no código nesta data; alterações posteriores no projeto exigem nova comparação.
