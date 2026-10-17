# Regras de design e layout — chAIt

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Data:** 2026-10-14  
> **Versão:** 1.0  
> **Base:** padrões aprovados de navegação, componentes, formulários e hierarquia dos blocos de fluxo.

## 1. Finalidade

Este guia orienta a criação, manutenção e revisão das telas do chAIt para administradores e atendentes. Uma nova tela deve reutilizar a estrutura e os componentes comuns antes de introduzir uma solução visual própria.

As regras vêm do [briefing de layout e design](design-interface-chait.md). O briefing registra implementação e validações; este documento reúne padrões reutilizáveis. Recomendações adicionais estão identificadas como propostas. A existência do guia não significa que todas as telas já atendam a cada critério.

Mudanças visuais devem preservar permissões, contratos do backend, dados existentes e regras de negócio. Quando uma necessidade exigir exceção, registrar o motivo e o comportamento nos dois temas e nas larguras afetadas.

## 2. Identidade e hierarquia

- Preservar a marca **chAIt**, o verde-petróleo e os temas claro, escuro e sistema.
- Dar maior destaque ao conteúdo que o usuário precisa ler e à ação que precisa executar.
- Usar título, descrição curta e ação principal para explicar a finalidade da página.
- Manter ajuda, metadados e informações de organização interna com destaque menor.
- Evitar que nomes internos ou descrições administrativas pareçam mensagens destinadas ao contato.
- Separar áreas por agrupamento, bordas e espaço. Não depender apenas da cor para indicar função ou estado.
- Manter a aparência e o comportamento dos controles ao trocar de módulo.

## 3. Tema, tipografia e dimensões

As cores vêm dos tokens semânticos de [app.css](../../resources/css/app.css). Não criar uma paleta independente por página nem fixar cores claras em componentes usados no tema escuro.

| Uso                      | Tokens                                       |
| ------------------------ | -------------------------------------------- |
| Página e texto principal | `--background` / `--foreground`              |
| Cards e modais           | `--card` / `--card-foreground`               |
| Ação principal           | `--primary` / `--primary-foreground`         |
| Ajuda e metadados        | `--muted-foreground`                         |
| Seleção e destaque suave | `--accent` / `--accent-foreground`           |
| Bordas e campos          | `--border` / `--input`                       |
| Foco de teclado          | `--ring`                                     |
| Ação destrutiva          | `--destructive` / `--destructive-foreground` |

O verde-petróleo principal no tema claro é `#0f766e`, com texto branco. Componentes devem consumir os tokens correspondentes. Sucesso, aviso e erro usam as cores semânticas existentes, acompanhadas de texto ou outro indicador compreensível.

| Elemento                     | Padrão                                        |
| ---------------------------- | --------------------------------------------- |
| Fonte                        | Instrument Sans, com alternativas sans-serif. |
| Texto principal da interface | 14 px.                                        |
| Campos e botões desktop      | 40 px de altura como padrão.                  |
| Alvos de toque               | Pelo menos 44 px.                             |
| Arredondamento de controles  | 8 px.                                         |

Reutilizar a escala de títulos dos layouts existentes. Ajuda e metadados podem usar texto menor, mantendo legibilidade. Nomes internos não devem ganhar destaque apenas para preencher espaço. Tamanhos compactos são exceções contextuais e devem preservar o alvo de toque no celular.

**Proposta complementar de espaçamento:** escala de 4, 8, 12, 16, 24, 32, 40 e 48 px; intervalos menores dentro de um campo e maiores entre grupos. Essa referência não substitui as dimensões aprovadas deste guia.

## 4. Navegação e composição das páginas

O menu principal pertence ao layout compartilhado. Conversas, Fluxos, editor, gatilhos e Configurações mantêm a mesma navegação e sua posição.

| Elemento                        | Regra                                                                            |
| ------------------------------- | -------------------------------------------------------------------------------- |
| Menu desktop                    | 64 px recolhido e 240 px expandido.                                              |
| Cabeçalho compartilhado         | 56 px; controle do menu no início, alinhado à esquerda.                          |
| Estado desktop                  | Uma fonte de estado no frontend, persistida pelo cookie `sidebar_state`.         |
| Navegação, atualização e Voltar | Preservar a preferência de expansão e indicar corretamente o item ativo.         |
| Controle do menu                | Ícone de painel lateral. O “X” é reservado para fechar modais e drawers.         |
| Marca                           | Componente próprio; ocultar rótulos do menu não pode ocultar ou deformar a logo. |
| Usuário e workspace             | Rodapé do menu, disponíveis conforme as permissões em todos os módulos.          |
| Configurações                   | Menu principal presente e navegação secundária horizontal acima do conteúdo.     |
| Editor                          | Canvas, ferramentas e propriedades ocupam o espaço restante do layout.           |

Links visíveis respeitam as permissões; ocultar um link não substitui a autorização do backend.

Páginas de cadastros começam com título, descrição curta, ação de criação e lista dos registros. Perfil, Segurança e integrações podem manter formulários contínuos em cards organizados. Propriedades dos blocos permanecem no painel do editor.

## 5. Botões e ícones

Reutilizar `AppButton` e os componentes comuns. A mesma função deve ter a mesma aparência em todos os módulos.

| Variante       | Uso                                                           |
| -------------- | ------------------------------------------------------------- |
| `primary`      | Ação principal do contexto, como Adicionar usuário ou Salvar. |
| `secondary`    | Ação alternativa, como Cancelar.                              |
| `danger`       | Ação destrutiva, com texto que identifica o efeito.           |
| `ghost`        | Ação discreta ou ferramenta contextual.                       |
| Botão de ícone | Ação reconhecível, com nome acessível e tooltip.              |

- Escrever ações com verbos claros: **Adicionar usuário**, **Adicionar à equipe**, **Salvar alterações**, **Cancelar**.
- Dar prioridade visual a uma ação principal por contexto.
- Usar Lucide com imports individuais de `@lucide/vue`; substituir símbolos de texto usados como ícones.
- Controles sem texto recebem nome acessível e tooltip. O tooltip sozinho não substitui o nome acessível.
- Durante envio, indicar carregamento e impedir submissão duplicada.
- Diferenciar foco, seleção, desabilitado e carregamento; manter o foco de teclado visível.
- Declarar o tipo do botão em formulários: envio usa `submit`; outras ações usam `button`.

## 6. Campos e formulários

- Rodapés de formulário usam `.form-actions` alinhado à direita. Ações de um registro ficam agrupadas à direita, com o espaçamento comum e quebra de linha quando necessário.
- Em grids de formulário, alinhar itens e conteúdo dos labels ao início; ajuda ou erros abaixo de um campo não devem deslocar o controle vizinho. Em linhas de palavras-chave, alinhar a base dos campos e do botão de remoção, sem margens verticais nos labels.
- Todo campo tem rótulo visível. Placeholder serve como exemplo ou orientação complementar.
- Agrupar campos relacionados e manter alinhamento, altura e espaçamento consistentes.
- Apresentar ajuda e erros junto ao campo; associá-los ao controle para leitores de tela.
- Identificar campos opcionais e explicar requisitos relevantes antes do envio.
- Preservar os valores quando houver erro de validação ou falha de envio.
- Não apresentar erros somente em notificações temporárias.
- Usar os componentes comuns de input, textarea, seletor e checkbox, com os mesmos estados visuais.
- Preservar tipos numéricos, valores nulos, opções dinâmicas e limites existentes. Uma mudança visual não altera o valor enviado à API.

Na Equipe, mostrar nome, email, função e permissões em uma lista alinhada. **Adicionar usuário** abre o modal, respeitando as regras administrativas e os caminhos de cadastro de conta nova e vínculo de conta existente.

## 7. Modais de cadastro e edição

Usuários, campos e tags são criados ou editados em modal aberto por uma ação explícita. O formulário de criação não fica permanentemente aberto na página da lista.

| Elemento                | Regra                                                                                 |
| ----------------------- | ------------------------------------------------------------------------------------- |
| Dimensões               | Máximo de 560 px de largura e margem mínima de 16 px em relação à janela.             |
| Conteúdo                | Título, descrição curta, campos agrupados e rolagem interna quando necessária.        |
| Fechar                  | Canto superior direito, com nome acessível.                                           |
| Rodapé                  | Cancelar e ação específica, como Adicionar à equipe.                                  |
| Foco                    | Foco inicial controlado, contido no modal e devolvido ao botão de abertura ao fechar. |
| Alterações pendentes    | Confirmar descarte antes de fechar, inclusive por Escape ou clique fora.              |
| Envio em andamento      | Bloquear fechamento e envio duplicado.                                                |
| Erro                    | Manter modal aberto, valores e erros junto aos campos.                                |
| Sucesso                 | Fechar, limpar o formulário e atualizar a lista.                                      |
| Cancelamento confirmado | Fechar e limpar dados e erros.                                                        |

Reutilizar `AppFormDialog`. Confirmações usam `AppDialog`, preservando suas chamadas, promises e fila existentes. No editor, sair com rascunho alterado também exige confirmação; carregar valores iniciais não deve marcar o rascunho como alterado.

## 8. Blocos de fluxo

### Painel de edição

- Abrir diretamente nas configurações específicas do bloco.
- Mostrar nome interno como título compacto, com lápis e tooltip **Editar nome interno do bloco**.
- Durante a edição, usar **Nome interno do bloco** e **Visível apenas para sua equipe**.
- Enter ou perda de foco confirma um nome válido; Escape cancela. Manter nome obrigatório e limite de 120 caracteres.
- Nome vazio ou inválido não substitui o valor válido nem permite salvar ou publicar silenciosamente.
- Em Conteúdo, usar **Mensagens para o contato**, **Texto da mensagem** e **Sequência de mensagens e ações**. Explicar que as esperas ocorrem entre os envios.
- Colocar a descrição ao final em **Organização interna**, recolhida por padrão, com **Descrição interna — opcional** e indicação de visibilidade para a equipe.
- Preservar descrições existentes e o limite de 500 caracteres. Recolher a seção ao selecionar outro bloco.

### Condição e exclusão de recursos

- Condição usa cartões numerados empilhados no painel esquerdo, com Combinar verificações, tipo, recurso, operador e expressão quando aplicável. Rótulos e erros ficam junto aos controles.
- Nome, E-mail e CPF aparecem em Campos padrão; personalizados de texto aparecem em grupo separado.
- Adicionar/remover verificações não altera conexões. Canvas mostra contagem, combinação, até duas verificações resumidas e pendências.
- Excluir campo/etiqueta usa confirmação explícita sobre perda dos valores/vínculos. Campos referenciados em rascunhos salvos ou prévias compartilhadas também são protegidos; etiquetas apenas em rascunhos não recebem esse bloqueio. Dependências de versões em uso e webhooks ativos são recusadas no backend e explicadas junto ao item. Ver [política de recursos](../modulos/bloco-condicao.md#8-proteção-contra-exclusão-de-campos-e-etiquetas).

### Prévia no canvas

- Separar o cabeçalho compacto de tipo e nome da prévia.
- Mostrar até dois itens da sequência e informar a quantidade restante.
- Identificar textos em balões como **Mensagem**, limitando cada texto a três linhas.
- Identificar arquivos e esperas com ícone e rótulo próprios.
- Retirar descrição interna da prévia. Prompts internos de IA também não aparecem como mensagens para o contato.
- Nos demais tipos, mostrar resumo das configurações, como duração da espera, operações ou destino.
- Usar estados vazios específicos, como **Adicione uma mensagem ou arquivo**.
- A prévia representa o conteúdo configurado; não indica que a mensagem já foi enviada.

Essas regras preservam API, banco, execução do fluxo e processo de salvar o rascunho.

## 9. Responsividade e rolagem

| Contexto                    | Comportamento                                                                                                                 |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| Abaixo de 1024 px           | Menu em drawer de 280 px, limitado a 88% da janela; estado independente da preferência desktop.                               |
| Navegação pelo drawer       | Fechar depois de navegar; manter o menu acessível no cabeçalho.                                                               |
| Chat no celular             | Uma área por vez: lista, conversa ou contato; preservar a conversa ao retornar do contato.                                    |
| Chat desktop                | Lista de 280 px, contato de 290 px e conversa com pelo menos 360 px.                                                          |
| Espaço insuficiente no chat | Contato passa a sobreposição quando a área disponível não comportar os três painéis, sem reduzir a conversa abaixo de 360 px. |
| Editor no celular           | Propriedades sobre o canvas, com fechamento explícito.                                                                        |
| Rolagem horizontal          | Não permitir na página. Navegação secundária pode ter rolagem própria; canvas permite pan horizontal.                         |

Avaliar o espaço disponível no conteúdo depois do menu. A largura total da janela não garante que todos os painéis do chat caibam.

Textos longos devem quebrar ou truncar de forma controlada, sem deslocar botões, deformar a marca ou alargar a página. Informações necessárias à tarefa precisam continuar acessíveis.

## 10. Estados e acessibilidade

- Prever carregamento, vazio, erro, sucesso e ausência de permissão quando aplicáveis.
- Estados vazios explicam o que falta e apresentam a próxima ação disponível.
- Erros explicam o problema e permitem correção ou nova tentativa sem perder dados.
- Navegação por teclado alcança ações em ordem coerente; foco permanece visível.
- Seleção e erro não dependem somente de cor. Verificar contraste nos dois temas.
- Modais e drawers têm título, fechamento acessível e gerenciamento de foco.
- Não desabilitar controles sem que o motivo possa ser compreendido.

## 11. Componentes e estilos

| Responsabilidade       | Referência                                                                                                                                                                               |
| ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Estrutura e navegação  | [AppLayout](../../resources/js/layouts/AppLayout.vue), [AppNavigation](../../resources/js/components/AppNavigation.vue), [AppBrand](../../resources/js/components/AppBrand.vue).         |
| Configurações          | [SettingsLayout](../../resources/js/layouts/SettingsLayout.vue).                                                                                                                         |
| Ações                  | [AppButton](../../resources/js/components/AppButton.vue), [AppIconButton](../../resources/js/components/AppIconButton.vue).                                                              |
| Cadastro e confirmação | [AppFormDialog](../../resources/js/components/AppFormDialog.vue), [AppDialog](../../resources/js/components/AppDialog.vue).                                                              |
| Campos                 | [Input](../../resources/js/components/ui/input/Input.vue), [Textarea](../../resources/js/components/ui/textarea/Textarea.vue), [AppSelect](../../resources/js/components/AppSelect.vue). |
| Blocos do fluxo        | [FlowBlockHeader](../../resources/js/components/FlowBlockHeader.vue), [FlowNode](../../resources/js/components/FlowNode.vue).                                                            |
| Tema                   | [app.css](../../resources/css/app.css).                                                                                                                                                  |

Usar shadcn-vue como base comum. Correções compartilhadas devem acontecer no componente responsável, mantendo compatibilidade com as chamadas existentes.

Estilos específicos de página devem ser isolados. Evitar seletores globais de página que alterem todos os botões, inputs, rótulos ou cards de outros módulos. Centralizar valores recorrentes no tema e nos componentes; não duplicar o menu ou o sistema de diálogos.

## 12. Checklist de revisão

- [ ] A tela reutiliza layout, tema e componentes comuns.
- [ ] Marca, controle do menu e item ativo permanecem corretos durante navegação, Voltar e atualização.
- [ ] Conversas → Fluxos → Configurações → Equipe → editor → Conversas funciona com menu aberto e recolhido, quando afetado pela alteração.
- [ ] Conteúdo, ação principal e metadados internos têm hierarquia clara.
- [ ] Botões de ícone têm nome acessível e tooltip; teclado e foco funcionam.
- [ ] Formulários apresentam rótulos, ajuda, erros e carregamento consistentes.
- [ ] Modais tratam sucesso, erro, cancelamento, descarte, foco e bloqueio durante envio.
- [ ] Nomes longos e estados vazios não quebram o layout.
- [ ] Temas claro e escuro foram inspecionados em 390, 768, 1024, 1280 e 1440 px nas áreas afetadas.
- [ ] A página não tem rolagem horizontal; canvas e navegação secundária mantêm apenas a rolagem própria.
- [ ] Alterações no editor preservam nome, descrição, sequência, rascunho e execução.
- [ ] Tipos, build e testes relevantes passaram quando houve alteração de código.
- [ ] Alterações de navegação e modais têm testes de navegador e inspeção visual; regressões de Equipe, permissões e diálogos foram verificadas quando afetadas.

A inspeção visual no navegador faz parte do aceite de layout. Tipos, build e testes automatizados complementam essa inspeção. Alterações apenas de documentação requerem revisão de conteúdo, formatação e links.

## Histórico

- **1.0 — 2026-10-14:** consolidação dos padrões de identidade, navegação, formulários, componentes, responsividade e hierarquia dos blocos de fluxo.
