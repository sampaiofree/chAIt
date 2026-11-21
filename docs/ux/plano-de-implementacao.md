# Plano de implementação de UX do chAIt

Atualizado em 21/11/2026. Planejamento baseado na revisão de `CHAIT-Design-System-UX-v1.md`, nas imagens de `docs/ux` e nas referências do editor apresentadas pelo responsável pelo produto. O andamento abaixo distingue implementação de validação.

## Direção

- Manter índigo nas ações principais, seleção suave na navegação e cores semânticas acompanhadas de texto. Distinguir pendências de erros, verificando contraste nos dois temas.
- Usar cards para unidades manipuladas individualmente. Usar linhas e seções para facilitar a comparação em listas e a leitura de formulários.
- Manter navegação lateral recolhível no desktop. No celular, manter atalhos inferiores nas listas principais e priorizar o espaço de conversa ou edição nas telas de trabalho.
- Usar a referência clara para a leveza visual e a escura para a correspondência entre seleção e painel. O painel sempre deve identificar o bloco selecionado.

## Etapas

### 1. Editor de fluxos — piloto implementado e validado localmente

- O canvas ocupa a área disponível. As propriedades abrem à direita em desktop amplo, sobre o canvas em largura intermediária e em toda a área de trabalho no celular.
- A seleção do canvas acompanha o painel. Os cards mostram tipo, nome, prévia e saídas. As pendências aparecem em âmbar e conduzem ao campo ou à seção correspondente. O painel de consulta de um fluxo ativo mantém a ação de fechar disponível.
- Os controles de zoom e ajuste de visualização ficam juntos. A faixa de ocorrências e execuções abre abaixo do canvas. No celular, o editor usa o espaço dos atalhos inferiores, que seguem disponíveis nas listas.
- A tela dedicada de IA mantém o bloco, a posição e o zoom ao voltar. No celular, o resumo conduz ao campo de correção após fechar a folha e o editor expandido devolve o foco ao botão de origem.

Em 21/11/2026, passaram a verificação de tipos, o build e 17 cenários de Chromium nos conjuntos de editor, IA e layout. Os testes agora abrem o workspace pelo link da conta autenticada. O cenário do piloto montou Início → Conteúdo → Aguardar resposta, configurou o campo, corrigiu as instruções de um bloco IA no rascunho e ativou o caminho conectado. O cenário cobriu também a consulta do fluxo ativo e a abertura por teclado. Houve inspeção e captura nos dois temas em 390, 768, 1024 e 1440 px. A ativação com IA conectada exige uma integração OpenAI configurada. A verificação de teclado virtual em aparelho físico segue pendente.

Capturas do piloto. [Canvas em desktop](../../artifacts/design-review/fluxos-piloto-block-canvas-light-1440.png) · [Propriedades em desktop](../../artifacts/design-review/fluxos-piloto-block-panel-dark-1440.png) · [Propriedades no celular](../../artifacts/design-review/fluxos-piloto-block-panel-light-390.png) · [IA no celular](../../artifacts/design-review/fluxos-piloto-ai-overview-390-dark.png)

#### Revisão do bloco IA e dos cards

- O bloco IA abre no painel comum e pode ser expandido pelo cabeçalho. As três guias são Conversa, Saídas e recursos e Configurações.
- Pendências levam à edição correspondente. O seletor de campos abre pelo botão Inserir campo. Os caminhos e recursos usam linhas expansíveis, com ações de adição separadas.
- Mídias, etiquetas e campos aceitam títulos internos opcionais, preservados no arquivo do fluxo e excluídos das instruções enviadas aos modelos. A inatividade aceita quatro unidades e continua sendo armazenada em segundos.
- Os cards dos oito tipos usam a mesma organização para identidade, prévia e saídas. Condição e Aguardar resposta identificam seus caminhos por texto e ícone.

Verificação local com Chromium em 390, 768, 1024 e 1440 px nos dois temas. Os testes cobriram título e prazo no salvamento automático, permissões de recursos após recarga, retorno da expansão, atalhos de teclado, prévias e saídas dos blocos. Os testes de transporte cobriram a importação dos títulos e confirmaram que eles ficam fora das instruções do atendimento.

Capturas da revisão. [Painel no desktop](../../artifacts/design-review/fluxos-ai-panel-1440-light.png) · [Painel no celular](../../artifacts/design-review/fluxos-ai-panel-390-dark.png)

### 2. Conversas e Leads — implementação em andamento

- Decisões de 21/11/2026: abas Todos / Meus / Não atribuídos com contadores que acompanham busca, status e etiquetas. A revisão do botão de filtros foi adiada; seu funcionamento atual permanece.
- Lista em duas linhas: nome e horário; prévia e selo de status. Responsável e ações de atendimento permanecem no painel do contato.
- Painel aberto quando há espaço no desktop, sobreposição sob demanda em largura intermediária e tela própria no celular.
- Ficha com edição direta e Salvar: identificação e Abrir conversa no topo; dados e campos personalizados na coluna principal, etiquetas e observações na secundária.
- Rascunhos por usuário/workspace/conversa em sessionStorage, restaurados após recarregar e limpos no logout. Navegação restaura seleção, busca, filtros e preferência do painel. Rolagem tem restauração local, condicionada ao histórico disponível.
- Verificação local: tipos e build passaram; dois testes de contadores passaram, incluindo busca e paginação; nove cenários de navegação e revisão responsiva passaram em Chromium. Capturas em `artifacts/design-review/conversas-etapa2-*` nos dois temas e quatro larguras.
- Limitações: suíte ampla de chat apresentou 37 falhas (várias respostas 503 do acesso à conexão) e 83 aprovações. Isso não certifica integração real. Teclado virtual físico e restauração de histórico além da janela carregada ainda precisam de validação.

### 3. Fluxos e Automação — planejado

- Alinhar busca, filtros, estado e criação nas listas de fluxos, gatilhos e sequências. Dar espaço para edição de etapas, condições e destinos.
- Aceite: localizar fluxo pelo nome, identificar seu estado e consultar a sequência ou gatilho associado.

### 4. Configurações e Administração — planejado

- Agrupar formulários por tarefa e apresentar estado e próxima ação nas integrações. Facilitar comparação e ações de registros de equipe e usuários.
- Aceite: identificar integração que exige atenção e editar usuário com as permissões existentes.

### 5. Acesso e conta — planejado

- Aplicar padrões comuns de campos, foco, erros e botões à autenticação, recuperação de acesso, perfil, segurança e suporte. Priorizar leitura nas páginas legais.
- Aceite: concluir recuperação de acesso e corrigir erro de formulário usando teclado e celular.

## Verificação de cada etapa

Inspecionar antes e depois nos temas claro e escuro, em 390, 768, 1024 e 1440 px. Verificar nomes longos, zoom, foco, contraste, toque, teclado virtual e estados de erro, salvamento e permissão. Executar tipos, build e testes de navegador pertinentes. Registrar resultados e limitações junto à etapa correspondente. Preservar APIs, dados e regras de negócio.
