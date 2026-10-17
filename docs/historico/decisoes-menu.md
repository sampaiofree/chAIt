> **Versão alternativa preservada:** contém decisões do Menu e também trechos anteriores à implementação. Consulte o [registro principal](../planejamento/decisoes.md). Esta cópia não substitui automaticamente as decisões do registro principal.

# Registro de decisões do briefing

> **Atualizado em:** 2026-10-14  
> **Regra de trabalho:** discutir uma decisão por vez e registrar apenas o que for explicitamente confirmado. A fase atual não inclui código.

## Decisões confirmadas nesta rodada

| ID | Decisão | Data | Documento responsável |
|---|---|---|---|
| B-01 | UAZAPI como provedor inicial, com [documentação oficial](https://docs.uazapi.com/). | 2026-10-12 | [Integração](../integracoes/uazapi.md) |
| B-02 | Um número de WhatsApp por workspace. | 2026-10-12 | [Multiatendimento](../modulos/chat-multiatendimento.md) |
| B-03 | Frontend Vue 3; Vue Flow para o editor de fluxos. Inertia.js permanece na arquitetura descrita. | 2026-10-12 | [Interface](../interfaces/chat.md) e [editor](../modulos/editor-de-fluxos.md) |
| B-04 | Começar pelo chat e multiatendimento; fluxos em etapa posterior. | 2026-10-12 | [Ordem de entrega](../README.md) |
| B-05 | Ao finalizar, liberar o responsável ativo e preservar o último atendente e histórico operacional. | 2026-10-12 | [Multiatendimento](../modulos/chat-multiatendimento.md) |
| B-06 | Manter conteúdo/histórico de mensagens somente na UAZAPI e aceitar histórico limitado; não persistir mensagens no Laravel. | 2026-10-12 | [Multiatendimento](../modulos/chat-multiatendimento.md) e [integração](../integracoes/uazapi.md) |
| B-07 | Atendimento humano só abre por gatilho que o determine; sem ele, nova mensagem segue processamento normal de palavras-chave/fluxos. | 2026-10-12 | [Multiatendimento](../modulos/chat-multiatendimento.md) |
| B-08 | Responsável atribuído ao lead ou administrador pode responder, finalizar e adicionar notas. | 2026-10-12 | [Multiatendimento](../modulos/chat-multiatendimento.md) |
| B-09 | Dois seletores: Todos / Meus chats / Não atribuídos e Todos / Abertos / Concluídos. | 2026-10-12 | [Interface](../interfaces/chat.md) |
| B-10 | Contato inicial com nome, email, CPF e notas. | 2026-10-12 | [Interface](../interfaces/chat.md) |
| B-11 | Anexos documento, imagem e vídeo; campo de busca na coluna de conversas. | 2026-10-12 | [Interface](../interfaces/chat.md) |
| B-12 | Continuar com a mesma aplicação web, adaptada para desktop e celular; sem criar frontend mobile independente. | 2026-10-12 | [Interface](../interfaces/chat.md) |
| B-13 | Bloco `Menu` com Botões, Lista e Numérico, compartilhando a mesma lógica de roteamento; uma saída por opção e `Continuar` para tempo ou erros. | 2026-10-14 | [Editor de fluxos](../modulos/editor-de-fluxos.md) |
| B-14 | Botões e Lista somente por seleção interativa real, sem aceitar texto digitado; Numérico somente pelo número válido. | 2026-10-14 | [Editor de fluxos](../modulos/editor-de-fluxos.md) |
| B-15 | Falha confirmada no envio interativo permite fallback Numérico; renderização não comprovada e resultado de envio incerto não autorizam reenvio automático indiscriminado. | 2026-10-14 | [Editor de fluxos](../modulos/editor-de-fluxos.md) e [UAZAPI](../integracoes/uazapi.md) |

As decisões anteriores de isolamento, visibilidade, atribuição manual, transferência imediata e notas internas permanecem nos documentos dos módulos.

## Menu — decisão de produto e dependência técnica

Aprovados em 14/10/2026: três apresentações para o mesmo bloco (`Botões`, `Lista`, `Numérico`); conexão distinta por opção; saída única `Continuar` ao esgotar o prazo ou o limite de erros. A seleção interativa é obrigatória nos formatos Botões e Lista; textos digitados, mesmo iguais ao rótulo, não contam como cliques. No Numérico, aceitar apenas o número correspondente. Respostas inválidas consomem tentativas e podem causar repetição da pergunta. Fallback para Numérico apenas quando o envio interativo tiver falha identificável.

**Condição técnica ainda não aprovada como funcionalidade comprovada:** a UAZAPI documenta `buttonOrListid` no modelo de mensagens, mas o webhook público não garante sua presença para todas as seleções/instâncias; testar antes de disponibilizar os formatos interativos. Limites de opções, valores padrão de tempo/erros e comportamento detalhado de falha operacional ficam sujeitos à homologação e definição técnica. Não converter uma mensagem digitada em clique por comparação textual.

## Histórico de mensagens — decisão confirmada

**Estado:** confirmado explicitamente em 12/10/2026, após apresentação da limitação do provedor.

**Regra atual aprovada:** não persistir conteúdo/histórico de mensagens no banco Laravel; consultar o provedor.

**Informação nova:** o [guia oficial de histórico](https://docs.uazapi.com/docs/collections/message-history-retention.md) informa retenção local de até sete dias, que pode ser menor sob alto volume. Recuperar mensagens anteriores depende do aparelho principal e não tem garantia de retorno.

**Escolha confirmada:** manter somente na UAZAPI e aceitar histórico limitado. Não implementar armazenamento de mensagens no Laravel. Notas internas, dados de contatos e histórico operacional continuam seguindo suas regras próprias.

**Consequência aceita:** o produto não garante acesso permanente ou completo às mensagens antigas. A interface deverá explicar indisponibilidade e recuperação pendente sem prometer histórico completo; o detalhamento visual fica para o protótipo.

## Nova mensagem após finalização — decisão confirmada

**Regra aprovada:** a mensagem sozinha não abre atendimento humanizado. A abertura depende de um gatilho definido para isso. Caso contrário, a conversa segue o comportamento normal, avaliando palavras-chave, fluxos e demais regras aplicáveis ao seu estado.

Isso substitui a recomendação anterior de reabrir automaticamente como Aberto. A regra não aprova preferência pelo último atendente, remoção de pausa manual nem retomada de execução interrompida.

**Dependência:** a primeira entrega do chat precede o executor. Definir o mecanismo de abertura humana disponível nessa fase; não presumir que mensagem recebida entra automaticamente na fila humana.

## Web e mobile — direção esclarecida

**Decisão confirmada:** continuar utilizando a mesma aplicação web em Vue 3 + Inertia, com layout/navegação adaptados para computador e celular. Mesmo projeto frontend, backend Laravel e dados. A proposta anterior de frontend mobile independente não corresponde à intenção do usuário e foi substituída.

**Proposta opcional, ainda não aprovada:** tornar essa mesma aplicação instalável como PWA. Ela continuará acessível normalmente pelo navegador. Instalação, notificações e eventual distribuição em lojas precisam de definição posterior; não presumir uso de Capacitor nem outro frontend.

Uma PWA utiliza tecnologias web e pode oferecer instalação no dispositivo a partir da mesma base de código. [Referência MDN](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps).

**Primeira entrega:** chat web com adaptação mobile, respeitando a navegação já aprovada. A decisão não promete funcionamento offline nem persistência local de mensagens.

## Demais detalhes a fechar, uma decisão por vez

1. Mecanismo de abertura humana na fase de chat, antes do executor de fluxos.
2. Agrupamento dos estados em Abertos e apresentação dos chats sem atendimento humano.
3. Campos pesquisados pela busca na coluna de conversas.
4. Obrigatoriedade/validação de nome, email e CPF e significado de notas do contato em relação às notas da conversa.
5. Edição/exclusão de notas e detalhes das ações administrativas; criação pelo responsável/admin já aprovada.
6. Pausa em Aberto, retomada após finalizar e tratamento de pausas manuais.
7. Detalhes técnicos da integração e dos anexos aprovados, atualização do chat e comportamento de indisponibilidade.
8. Fluxos posteriores: captura/sobrescrita de respostas, pausa durante espera, limites, falhas, teste, permissões, exclusão e saídas sem conexão.

## Dependências conhecidas

- Vue foi escolhido, mas a base existente ainda usa React. A adaptação depende de uma fase futura de implementação.
- Finalização libera o responsável; nova mensagem só abre atendimento humano por gatilho. Preferência pelo último atendente e retomada do robô ainda não foram aprovadas.
- O bloco Aguardar Resposta prevê persistir uma resposta em campo do contato; formalizar essa fronteira antes do executor.
- Ler a documentação pública não valida automaticamente a versão do servidor contratado.
- Botões/Lista do bloco Menu só entram em produção após verificar que os eventos de seleção são inequívocos no webhook da instância real; a existência de `buttonOrListid` no modelo público não garante presença em todo evento.

## Histórico

- **2026-10-12:** decisões B-01 a B-04 confirmadas e documentos organizados.
- **2026-10-12:** B-05 confirmada em resposta explícita: liberar responsável e preservar histórico.
- **2026-10-12:** identificada retenção limitada da UAZAPI; revisão da política de histórico apresentada como próxima decisão pendente.
- **2026-10-12:** B-06 confirmada: manter somente na UAZAPI e aceitar histórico limitado. Próximo ponto: nova mensagem após finalização.

- **2026-10-12:** B-07 a B-11 confirmadas: gatilho humano, atores das ações, seletores, campos e anexos/busca. Estrutura mobile apresentada como proposta, aguardando decisão.

- **2026-10-12:** B-12 esclarecida: mesma aplicação web em desktop/celular, sem frontend mobile independente. Instalação PWA permanece proposta opcional.
- **2026-10-14:** B-13 a B-15 aprovadas: bloco Menu com três formatos, saídas por opção + `Continuar`, validação restrita de escolhas; documentação pública UAZAPI estudada, homologação de webhook ainda pendente.
