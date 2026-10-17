# Checklist de implementação e homologação

> **Atualizado em:** 2026-10-17  
> **Base:** análise da documentação e do código, consolidada em 16/10/2026.  
> **Status:** acompanhamento de pendências; não define prioridade nem aprova novos recursos.

## Checklist de recursos

- [ ] **Módulo de Leads:** criar listagem, busca, filtros, cadastro manual e ficha própria dos contatos. Os contatos já existem e são utilizados pelo chat. Ver [briefing de Leads](../../modulos/leads.md).
- [x] **Transferência para outro atendente — implementada, conferida no código em 17/10/2026:** administrador pode escolher membro ativo como responsável; há também Assumir para mim. O item estava pendente na análise anterior. Referências: `UazapiChatController::assignTo`, serviço `HumanAssignment` e controles de responsável no dashboard. Ver [contrato atual](../../modulos/chat-multiatendimento.md#52-transferência-explícita). Esta conferência foi estática, sem novos testes operacionais.
- [ ] **Permissões mais detalhadas:** implementar controles independentes para visualizar todas/próprias/não atribuídas, transferir atendimentos, visualizar/editar leads e criar etiquetas/campos, conforme os respectivos contratos. A autorização atual por papel, atribuição e `can_view_unassigned` já existe. Ver [Chat](../../modulos/chat-multiatendimento.md#4-acesso-e-visibilidade-das-conversas) e [Leads](../../modulos/leads.md#7-permissões-e-isolamento).
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

## Recursos implementados que aguardam homologação ou liberação

Estes itens já têm código; os checks abaixo acompanham a validação operacional, não uma implementação do zero.

- [ ] **Chat em tempo real:** validar infraestrutura, ativação e operação real do caminho controlado por configuração. Ver [guia operacional](../../operacao/chat-realtime.md).
- [ ] **Menu com Botões/Lista:** validar seleções pelo webhook público e registrar os perfis da conexão antes da liberação. Há registros de envio e apresentação no iPhone. Ver [Bloco Menu](../../modulos/bloco-menu.md).
- [ ] **Elemento Botão do Conteúdo:** homologar renderização e ação por tipo/conexão; para Resposta, validar o evento real no webhook. Ver [contrato do elemento](../../modulos/bloco-conteudo-elemento-botao.md).
- [ ] **Chips de campos do contato:** comprovar que a UAZAPI preserva o conteúdo preparado nos caminhos relevantes e registrar a homologação por conexão. Ver [contrato dos campos](../../modulos/campos-do-contato-fluxos.md).

## Como manter o checklist

- Marcar um recurso como concluído somente com implementação e verificação correspondentes; registrar data e referência ao código, teste ou evidência.
- Para homologação, registrar o recurso, conexão/ambiente e cenários efetivamente validados, sem incluir credenciais ou dados pessoais.
- Atualizar também o documento responsável quando o comportamento mudar.
- Este documento consolida os itens da análise citada; não representa uma auditoria exaustiva ou contínua de todas as funcionalidades futuras.
