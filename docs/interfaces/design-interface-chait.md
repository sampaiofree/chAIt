# Layout e design — chAIt

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Data:** 2026-10-14  
> **Versão:** 2.1  
> **Status:** briefing aprovado e implementado; validação visual e funcional local.

## Objetivo e decisões aprovadas

As regras reutilizáveis para criar e revisar telas estão no [Guia de regras de design e layout](regras-design-layout.md). Este briefing mantém o registro das decisões e da validação da revisão.

Padronizar a interface inteira para administradores e atendentes, mantendo a identidade chAIt, o destaque verde-petróleo e os temas claro, escuro e sistema.

- Menu principal permanente em Conversas, Fluxos, editor, gatilhos e Configurações.
- Cadastros de usuários, campos e tags em modal, aberto por uma ação explícita.
- Base compartilhada shadcn-vue e ícones Lucide, com imports individuais de `@lucide/vue`.
- Configurações contínuas em cards; propriedades dos blocos no painel do editor.
- Mesma aplicação Vue + Inertia para desktop e celular; regras de negócio e APIs preservadas.

## Navegação compartilhada

O layout persistente `AppLayout` mantém a navegação, o cabeçalho e a preferência de expansão durante transições Inertia. O estado desktop é inicializado pelo cookie `sidebar_state`, com os dados compartilhados do backend como alternativa, e atualizado por um único composable.

| Elemento                    | Padrão                                                                        |
| --------------------------- | ----------------------------------------------------------------------------- |
| Menu desktop recolhido      | 64 px                                                                         |
| Menu desktop expandido      | 240 px                                                                        |
| Cabeçalho compartilhado     | 56 px                                                                         |
| Navegação abaixo de 1024 px | Drawer de 280 px, limitado a 88% da janela                                    |
| Controle do menu            | Ícone de painel lateral no início do cabeçalho                                |
| Marca                       | Componente próprio, sempre exibindo chAIt completo                            |
| Usuário e workspace         | Rodapé do menu; seletor também disponível em Configurações e Fluxos           |
| Seções de Configurações     | Navegação horizontal acima do conteúdo, com rolagem própria quando necessário |

O drawer mobile tem estado independente da preferência desktop e fecha ao navegar. A navegação mostra somente os módulos permitidos. As permissões continuam sendo aplicadas pelo backend.

O editor compartilha o menu principal. Ao tentar sair com alterações não salvas, apresenta confirmação de descarte. A inicialização dos valores padrão do editor não deve gerar um aviso de alterações pendentes.

## Conversas e responsividade

No desktop, a lista tem 280 px e o painel do contato, 290 px. A conversa ocupa o espaço restante. Quando a área disponível não comporta lista, conversa de pelo menos 360 px e painel, o perfil passa a uma sobreposição de 290 px. A preferência manual de recolhimento permanece independente dessa adaptação.

Abaixo de 1024 px, lista, conversa e perfil ocupam visualizações separadas. Retornar do perfil mantém a conversa selecionada; retornar da conversa mostra a lista. O menu geral permanece acessível no cabeçalho.

A página não deve ter rolagem horizontal. O canvas do editor mantém pan e zoom próprios. No celular, as propriedades de um bloco aparecem sobre o canvas e podem ser fechadas.

## Formulários e componentes

Equipe abre pela lista de membros, com nome, email, função e permissões. Administradores têm o botão **Adicionar usuário**. O formulário não ocupa a página antes da abertura.

Os modais de usuários, campos e tags seguem o mesmo contrato:

- Largura máxima de 560 px e margem mínima de 16 px nas bordas da janela.
- Título, descrição, fechar no canto superior direito e Cancelar/Salvar no rodapé.
- Foco inicial no primeiro campo, navegação de teclado contida e retorno ao botão de abertura.
- Erros do backend apresentados junto aos campos; valores preservados após falha.
- Envio em andamento bloqueia fechamento e submissão duplicada.
- Alterações pendentes exigem confirmação de descarte; formulários intactos fecham diretamente.
- Sucesso fecha o modal e atualiza a lista; cancelamento limpa dados e erros.

`AppButton` preserva as variantes primary, secondary e danger, e acrescenta ghost, tamanho e carregamento. `AppDialog` mantém o contrato de promises e fila do composable existente, usando a base de diálogo compartilhada. Inputs preservam modelos numéricos e campos opcionais. Seletores nativos com valores dinâmicos compartilham acabamento e ícone Lucide, preservando seus contratos de seleção.

As páginas utilizam estilos isolados. O tema concentra cores, dimensões e foco; foram retiradas as sobrescritas amplas de botões, campos e superfícies entre módulos.

| Padrão                       | Definição                                                             |
| ---------------------------- | --------------------------------------------------------------------- |
| Fonte                        | Instrument Sans, com alternativas sans-serif                          |
| Texto principal da interface | 14 px                                                                 |
| Controle desktop             | 40 px                                                                 |
| Alvos de toque               | Pelo menos 44 px                                                      |
| Arredondamento de controles  | 8 px                                                                  |
| Ícones                       | Lucide, com nome acessível; controles sem texto têm tooltip ou título |
| Estados                      | Foco, seleção, desabilitado, carregamento, erro e sucesso             |

## Alinhamentos revisados em 16/10/2026

- Rodapés `.form-actions` usam o alinhamento global à direita, inclusive nas configurações, sem sobrescrita para a esquerda no layout.
- Listas de campos e tags agrupam Editar/Excluir em `.record-actions`, com espaçamento comum, margem automática à esquerda e quebra de linha alinhada à direita.
- Palavras-chave usam labels sem margem dentro de `.rule`; Remover alinha à base dos controles, checkbox centralizado e botão à direita nas colunas 2–3 até 760 px.
- No editor de Sequências, o grid de nome/fuso alinha itens ao início e os labels usam `align-content: start`; ajuda e erros não deslocam os controles. Até 640 px, os campos ficam em uma coluna.

Esses registros descrevem os ajustes implementados. A revisão documental não substitui nova inspeção visual em dispositivos reais.

## Hierarquia dos blocos do fluxo

O painel prioriza as configurações específicas do bloco. O nome é um título compacto no cabeçalho, editável pelo lápis **Editar nome interno do bloco**. O campo aparece apenas durante a edição, com a indicação **Visível apenas para sua equipe**. Enter ou perda de foco confirma um nome válido; Escape cancela. O limite permanece em 120 caracteres e o nome continua obrigatório. Nomes vazios não substituem o valor anterior e bloqueiam Salvar/Publicar enquanto a edição inválida estiver aberta.

A descrição fica no final, em **Organização interna**, recolhida por padrão e novamente recolhida ao selecionar outro bloco. O campo **Descrição interna — opcional** mantém o limite de 500 caracteres e o valor existente, com a mesma indicação de visibilidade interna.

Em Conteúdo, os rótulos são **Mensagens para o contato**, **Sequência de mensagens e ações** e **Texto da mensagem**. As instruções explicam que esperas são pausas entre os envios.

No canvas, tipo e nome formam um cabeçalho compacto, separado da prévia. São mostrados até dois itens da sequência; mensagens usam balões identificados como **Mensagem**, arquivos mostram tipo/nome/legenda e esperas mostram a duração. Textos são limitados a três linhas e o restante aparece como uma contagem de itens. As prévias representam a configuração, sem indicar que o conteúdo já foi enviado.

A descrição interna e o prompt da IA não aparecem como conteúdo enviado. Início, espera de resposta, operações, condição, destino e IA mostram resumos das configurações ou orientações específicas quando vazios. Os contratos de API, os campos persistidos e a execução dos fluxos permanecem os mesmos.

## Validação e manutenção

Executar `npm run types:check`, `npm run lint:check`, `npm run format:check`, `npm run test:voice` e os testes PHP de configurações, equipe, fluxos e isolamento de workspace.

`npm run test:browser` compila a aplicação e inicia um servidor Playwright com schema PostgreSQL descartável `chait_browser_<identificador>` em `chait_test`, carregando `.env.testing`. Banco, sessões e fixtures seguem o [isolamento de testes](../desenvolvimento/postgresql.md#testes-isolados). O servidor não utiliza o banco de desenvolvimento nem o hot-file do Vite. As respostas do provedor WhatsApp são simuladas nos testes de interface; nenhuma mensagem real é enviada.

A cobertura de navegador inclui:

- Conversas → Fluxos → Configurações → Equipe → editor → Conversas, menu aberto/recolhido, atualização e histórico.
- Identidade preservada e instância persistente do cabeçalho durante navegação Inertia.
- Criação de conta, vínculo de conta existente, validação, foco, descarte e bloqueio durante envio.
- Criação/edição de campos e tags e cancelamento de formulários intactos.
- Proteção de rascunhos do editor, formatação de texto, envio de valores numéricos e navegação conforme permissões.
- Temas claro/escuro em 390, 768, 1024, 1280 e 1440 px, sem overflow da página.
- Drawer mobile e painel do contato em sobreposição.

A inspeção visual usa capturas de tela nos dois temas e uma revisão da sessão local. Testes do provedor e mídia permanecem sujeitos à homologação real já prevista nos documentos de integração.

## Histórico

- **v2.1 — 2026-10-14:** nome interno discreto, descrição recolhida e prévias que distinguem mensagens, arquivos, esperas e ações; testes de edição, persistência, estados vazios e temas.

- **v2.0 — 2026-10-13:** implementado o briefing de padronização, menu permanente, modais, componentes compartilhados e validação responsiva.
- **v1.0 — 2026-10-12:** registrada a direção visual inicial e as recomendações de design.
