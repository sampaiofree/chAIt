# chAIt — Design System e Diretrizes de UX (v1.0)

**Data:** 19/11/2026  
**Objetivo:** padronizar aparência, componentes, interação e layouts responsivos em todo o chAIt.  
**Público:** desenvolvimento frontend, produto e QA.  
**Escopo:** design visual e UX de desktop e mobile. **Não inclui** publicação em lojas, empacotamento, configuração de PWA, API ou alterações em regras de negócio.

## 1. Decisões de produto

### Decisões confirmadas pelo responsável pelo produto

- **Identidade:** moderna e tecnológica, sem efeitos visuais excessivos.
- **Cor primária:** índigo `#4F46E5`.
- **Superfícies:** fundo cinza muito claro e cards brancos no tema claro; equivalentes de baixo brilho no escuro.
- **Princípio de UX:** simplicidade primeiro.
- **Público:** tanto iniciantes quanto profissionais experientes.
- **Dispositivos:** desktop é prioridade para funcionalidades complexas; mobile deve ter UX própria e servir como interface do aplicativo já disponibilizado nas lojas.
- **Tecnologia já definida em documentos do projeto:** Vue 3 + Inertia.js no frontend, com Vue Flow para o editor de fluxos em etapa posterior.

### Interpretação prática dessas decisões

- **Uma experiência coerente, não telas idênticas:** mesmos componentes, linguagem e dados, mas arranjos distintos por dispositivo.
- **Divulgação progressiva:** campos essenciais visíveis; opções avançadas em seções recolhíveis, sem esconder ações críticas.
- **Ações previsíveis:** mesma cor, posição e rótulo para ações equivalentes em qualquer módulo.
- **Poder sem poluição:** atalhos, busca e ações rápidas podem coexistir com interface simples.
- **Sem "modo iniciante/avançado" separado.**

> Os detalhes dimensionais e tokens abaixo são padrões de design estabelecidos nesta versão; não alteram regras funcionais previamente aprovadas. Quando houver conflito com especificação funcional, a regra funcional prevalece e o layout deverá ser ajustado.

## 2. Tokens de cor

### Tema claro

| Token | HEX | Aplicação |
|---|---|---|
| `--color-primary` | `#4F46E5` | Botão primário, seleção, foco |
| `--color-primary-hover` | `#4338CA` | Hover de ação primária |
| `--color-primary-soft` | `#EEF2FF` | Área selecionada e realce leve |
| `--bg-app` | `#F8FAFC` | Fundo geral |
| `--bg-surface` | `#FFFFFF` | Cards, painéis, menus |
| `--bg-subtle` | `#F1F5F9` | Inputs e áreas de contraste secundário |
| `--border-default` | `#E2E8F0` | Bordas e divisórias |
| `--text-primary` | `#0F172A` | Títulos e conteúdo principal |
| `--text-secondary` | `#475569` | Texto auxiliar |
| `--text-muted` | `#64748B` | Legendas e placeholders |
| `--color-success` | `#15803D` | Sucesso |
| `--color-warning` | `#B45309` | Atenção |
| `--color-danger` | `#B91C1C` | Erro e ações destrutivas |
| `--color-ai` | `#7C3AED` | Identificação pontual de recursos IA |

### Tema escuro

| Token | HEX | Aplicação |
|---|---|---|
| `--color-primary` | `#4F46E5` | Fundo dos botões primários |
| `--color-primary-hover` | `#4338CA` | Hover de ação primária |
| `--color-primary-soft` | `#1E1B4B` | Seleções em superfícies escuras |
| `--color-link` | `#A5B4FC` | Links em fundos escuros |
| `--bg-app` | `#0B1220` | Fundo geral |
| `--bg-surface` | `#111827` | Cards, painéis, menus |
| `--bg-subtle` | `#1E293B` | Inputs e seções secundárias |
| `--border-default` | `#334155` | Bordas e divisórias |
| `--text-primary` | `#F8FAFC` | Títulos e conteúdo principal |
| `--text-secondary` | `#CBD5E1` | Texto auxiliar |
| `--text-muted` | `#94A3B8` | Legendas e placeholders |
| `--color-success` | `#4ADE80` | Sucesso |
| `--color-warning` | `#FBBF24` | Atenção |
| `--color-danger` | `#F87171` | Erro |
| `--color-ai` | `#C4B5FD` | Identificação pontual de recursos IA |

**Regras:**
- Nunca comunicar estado apenas pela cor; usar ícone/rótulo.
- Ícones de IA podem usar violeta, mas ações primárias continuam índigo.
- Não usar gradientes em áreas de conteúdo ou componentes de uso recorrente. Aplicações decorativas devem ser raras.
- Verificar contraste de texto e controles segundo WCAG AA; não presumir que todo par de tokens acima serve para qualquer combinação.
- Disponibilizar Claro / Escuro / Seguir sistema; preservar a escolha do usuário por conta ou por dispositivo conforme a arquitetura existente.

## 3. Tipografia, medidas e iconografia

- **Fonte sugerida:** Inter, com fallback `system-ui, sans-serif`.
- **Texto principal:** 14–16px; textos de ação e formulários tipicamente 14px ou 16px; evitar texto funcional abaixo de 12px.
- **Títulos:** 24px (página), 18–20px (seção), 15–16px (card).
- **Pesos:** 400 regular, 500 medium, 600 semibold; usar 700 pontualmente.
- **Ritmo de espaçamento:** escala de 4px (4, 8, 12, 16, 20, 24, 32).
- **Raio:** 8px inputs/botões; 12px cards; 16px modais/painéis de destaque.
- **Bordas:** 1px discreta; evitar sombras fortes. Tema escuro privilegia contraste por superfície e borda, não sombra.
- **Biblioteca de ícones:** Lucide. Ícones acompanham rótulos em ações não óbvias.
- **Densidade:** espaçamento confortável por padrão; evitar grandes áreas vazias em formulários longos.

## 4. Componentes e interações

### Botões

| Tipo | Quando usar | Comportamento |
|---|---|---|
| Primário | Ação principal da tela ou seção | Índigo preenchido; preferencialmente um CTA predominante por região |
| Secundário | Alternativa relevante | Superfície neutra com borda |
| Terciário / texto | Ação discreta | Sem preenchimento; área de clique adequada |
| Perigoso | Excluir, desconectar, descartar | Vermelho e confirmação quando irreversível |
| Ícone isolado | Apenas ação amplamente reconhecível | Tooltip desktop e rótulo acessível; nunca ícone ambíguo sem ajuda |

- Estados obrigatórios: normal, hover (desktop), foco visível, pressionado, carregando e desabilitado (com motivo acessível quando possível).
- Não usar disabled como forma padrão de orientar usuário sobre erro; prefira CTA habilitado com validação clara ou explicação precisa sobre o bloqueio.
- Não duplicar "Salvar" em diversas posições com comportamentos contraditórios.

### Formulários

- Rótulo acima do campo; placeholder serve de exemplo, nunca substitui label.
- Ajuda contextual complementar em `AppFieldHelp`, com balão flutuante aberto por clique ou toque; não expandir o formulário.
- Validação no campo, em linguagem humana; ao salvar, destacar e localizar o primeiro erro.
- Agrupar por objetivo do usuário, não por detalhe técnico de implementação.
- Preferir seleção, exemplos e valores padrão a exigir texto livre sem orientação.
- Em editores longos, indicar alterações não salvas e proteger contra perda acidental.

### Feedback e estados

- Notificação flutuante compartilhada: sucesso por 5 segundos; erros e alertas persistentes e acionáveis. Erros de preenchimento permanecem junto aos campos.
- Skeleton durante carregamento; estado vazio com explicação e CTA; falha de rede com tentativa de reconectar.
- Para operações demoradas, mostrar progresso/status sem bloquear toda a aplicação desnecessariamente.
- Status de publicação, integração, atendimento e execução precisam ter rótulos explícitos.
- Evitar modais de confirmação para ações reversíveis; usar confirmação para ações destrutivas relevantes.

## 5. Arquitetura de layout — desktop

**Prioridade:** trabalho simultâneo com múltiplos contextos, boa densidade informacional, espaço para edição.

- **Menu global à esquerda:** 240px expandido / aproximadamente 68px recolhido, com seleção do workspace preservada.
- **Cabeçalho:** título da página, contexto/breadcrumb quando necessário, status e ação principal bem localizados.
- **Conteúdo:** fundo `--bg-app`, seções em cards `--bg-surface`; largura fluida, mas texto de formulário com largura legível (não esticar um campo textual até a largura inteira de monitor ultra-wide).
- **Páginas complexas:** navegação interna por seções e área de trabalho estável; não exigir ida e volta entre várias páginas para concluir uma edição.
- **Formulários com muitas configurações:** usar coluna principal para campos e outra para teste/prévia/resumo, quando houver espaço.

### Chat — preservar decisões existentes

O documento `chat.md` já estabelece quatro áreas no desktop: **menu do produto → lista de conversas → conversa ativa → dados do contato**. Manter esse modelo. Em larguras intermediárias, permitir recolher o painel de contato; evitar quatro colunas permanentemente comprimidas.

### Editor de IA — view complexa

- É adequado manter uma **página própria de configuração** para bloco IA complexo, em vez de obrigar tudo a caber no painel lateral do canvas.
- Topo: nome do bloco, status, voltar ao fluxo e ações Salvar / Testar de forma previsível.
- Organizar a edição por intenções claras: **Instruções**, **Conhecimento**, **Ações**, **Teste** e **Avançado** (ajustar denominações ao conteúdo realmente implementado).
- Dentro da seção, essencial primeiro; parâmetros raros em acordeões.
- Instruções extensas com editor de largura confortável, exemplos e possibilidade de expansão.
- Prévia de conversa / simulação acessível sem descartar alterações; não enviar mensagem real sem informar explicitamente.
- "Configuração pendente" deve apontar a seção/campo exato que precisa de atenção.

**Ponto crítico:** não transformar cada opção em uma aba nova; isso fragmenta a tarefa e dificulta descobrir relações entre parâmetros.

## 6. Arquitetura de layout — mobile / aplicativo

**Princípio:** adaptar tarefas, não escalar o desktop. Uma coluna, foco na atividade atual, controles de toque e continuidade de contexto.

### Navegação

- **Menu global em drawer**, conforme decisão anterior do projeto. Workspace atual visível e seleção acessível.
- **Cabeçalho compacto:** voltar, título e no máximo 1–2 ações principais; demais ações no menu contextual.
- **Uma tela ou etapa de trabalho por vez**, com retorno sem perda de dados.
- Não introduzir barra inferior global por padrão: evitar navegação duplicada com o drawer já aprovado.
- Controles em áreas de toque de pelo menos aproximadamente 44 × 44px e com separação adequada; respeitar safe areas de iOS/Android e teclado virtual.

### Chat mobile — fluxo já aprovado

1. **Lista de conversas:** busca e acesso claro aos dois seletores já aprovados (atribuição e status). Filtros podem abrir seletor compacto ou folha inferior, mantendo os mesmos critérios funcionais.
2. **Conversa:** tela inteira, cabeçalho do contato, mensagens e composer na base. Teclado não deve cobrir campo de resposta nem botão de enviar.
3. **Contato:** abrir dados ao tocar no cabeçalho da conversa; permitir voltar sem perder posição, filtro ou rascunho.

- Nota interna deve ser visualmente e semanticamente diferente de mensagem enviada ao cliente.
- Ações Assumir, Transferir e Finalizar devem ser visíveis conforme permissão, sem alterar suas regras de negócio.
- Erros/indisponibilidade do histórico WhatsApp devem ser claros, pois a documentação atual não garante histórico completo.

### Editor de IA mobile

- **Página própria em uma coluna**, em vez de reduzir o formulário desktop.
- Seções curtas e dobráveis; índice de seções para pular diretamente até Instruções, Conhecimento, Ações, Teste e Avançado.
- Editor de prompt com opção **Expandir para edição em tela cheia**.
- Teste/simulação em tela separada ou sheet de tela cheia, com retorno ao formulário preservando o texto não salvo.
- Ação Salvar em posição previsível (rodapé fixo quando não conflitar com teclado/safe area); mostrar estado de salvamento.
- Evitar colunas lado a lado, tabelas amplas e campos com rótulos truncados.

### Editor de fluxos mobile

- Canvas com zoom, arraste e botão de recentralização; acesso aos nós por seleção ou lista alternativa.
- Abrir configuração do nó em **tela ou sheet de tela cheia**, não em painel lateral estreito.
- Permitir visualização e operações fundamentais com controles claros; funções avançadas devem receber alternativas próprias para toque, sem depender exclusivamente de drag-and-drop preciso.
- **Validar protótipo antes de afirmar paridade funcional completa:** edição visual de grafos grandes no celular pode ser inerentemente menos produtiva que no desktop. Se algo não for suportado, indicar explicitamente no produto em vez de ocultar sem explicação.

### Contatos, tabelas e configurações mobile

- Substituir tabelas muito largas por cards/listas com dados prioritários e detalhes ao tocar.
- Usar filtros compactos, busca acessível e ações contextuais; não esconder a informação essencial em menus profundos.
- Preferir formulários de coluna única; agrupar dados de contato em seções editáveis.

## 7. Breakpoints e comportamento responsivo

Valores iniciais para prototipação e testes, ajustáveis conforme conteúdo real:

| Faixa | Referência | Direção de layout |
|---|---|---|
| Compacto | `0–767px` | Uma coluna; drawer; telas dedicadas; ações adaptadas ao toque |
| Intermediário | `768–1199px` | Menu recolhido; conteúdo fluido; painéis secundários sob demanda |
| Amplo | `≥1200px` | Navegação lateral e painéis múltiplos quando úteis |

**Regra superior ao breakpoint:** se o conteúdo ficar ilegível/comprimido, mudar o arranjo mesmo antes do limite de largura.

## 8. Acessibilidade e qualidade de UX

- Mirar **WCAG 2.2 AA** nas interfaces e fluxos críticos.
- Garantir foco visível, navegação por teclado, labels para leitores de tela, estados de erro compreensíveis e movimento reduzido quando solicitado.
- Incluir os estados **vazio, carregando, erro, sem permissão, conexão perdida, salvando e alteração não salva** nos protótipos.
- Testar zoom de navegador, textos longos, nomes extensos, múltiplas mensagens, teclado virtual e orientação mobile.
- Evitar confirmação apenas por mudança de cor ou notificações que desaparecem antes da leitura.

## 9. Componentes reutilizáveis sugeridos para a base Vue

`AppShell`, `WorkspaceSwitcher`, `SidebarNav`, `PageHeader`, `SectionCard`, `BaseButton`, `BaseInput`, `BaseSelect`, `BaseTextarea`, `StatusBadge`, `InlineAlert`, `ConfirmDialog`, `BottomSheet`, `EmptyState`, `LoadingState`, `ThemeSwitcher`, `UnsavedChangesGuard`.

- Componentes recebem tokens sem valores de cor repetidos em arquivos isolados.
- Separar **componente visual** de **regra de negócio**; permissões e isolamento de workspace continuam validados pelo backend.
- Formulários devem reutilizar padrões de validação, estados e mensagens.
- Testes visuais mínimos nos dois temas e nas três faixas responsivas.

## 10. Critérios de aceite para frontend/QA

- [ ] Temas claro e escuro têm contraste adequado, sem componentes ilegíveis.
- [ ] Os mesmos tipos de botão, entrada, card e alerta têm aparência e funcionamento consistentes em todos os módulos.
- [ ] A interface desktop não estica texto longo inutilmente nem usa cards para cada pequeno campo.
- [ ] O menu desktop pode ser expandido/recolhido e o menu mobile é um drawer.
- [ ] Chat desktop mantém quatro áreas; chat mobile segue lista → conversa → contato.
- [ ] Os **dois seletores aprovados** do chat permanecem disponíveis e equivalentes em desktop e mobile.
- [ ] Ao editar dados longos no celular, há espaço suficiente, teclado não bloqueia ações e rascunho é preservado.
- [ ] Ao salvar com erros, a pessoa encontra rapidamente o campo responsável.
- [ ] Status crítico aparece com texto e não depende somente da cor.
- [ ] Interface apresenta estados vazio, loading, erro de rede e sem permissão.
- [ ] Elementos clicáveis no mobile têm tamanho adequado para toque.
- [ ] Editor de IA possui navegação clara por seções e teste acessível sem perda de alterações.
- [ ] Editor de fluxos mobile tem estratégia explícita de interação por toque, a validar em protótipo.

## 11. Relação com documentos existentes

Este Design System **complementa, mas não substitui**:

- `chat.md` — interface do chat, quatro áreas desktop, drawer e navegação mobile.
- `chat-multiatendimento.md` — estados, permissões, atribuições, notas e regras de atendimento.
- `editor-de-fluxos.md` — contratos de blocos, canvas e etapas de implementação.
- `decisoes.md` — decisões de produto e pendências funcionais.

Qualquer decisão funcional adicional deve ser tratada nos documentos de domínio correspondentes. O presente documento cobre apenas apresentação e experiência.

---

**Próximo trabalho recomendado para o desenvolvedor:** criar um protótipo responsivo de **uma tela complexa** (configuração do bloco IA) nos temas claro e escuro, desktop e mobile, antes de aplicar o Design System ao resto da aplicação. Isto reduz o risco de padronizar componentes inadequados para a parte mais complexa do sistema.

### Padrão atualizado de feedback (21/11/2026)

Seguir o [padrão obrigatório de avisos e ajuda flutuante](../interfaces/regras-design-layout.md#avisos-e-ajuda-flutuante--padrão-obrigatório-21112026), com componentes compartilhados, regras de duração, acessibilidade e checklist. Esta regra substitui orientações anteriores de ajuda expansível.
