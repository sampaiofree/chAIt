# Interface do Chat — Desktop e Mobile

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Projeto:** SaaS de atendimento multicanal em Laravel  
> **Arquivo:** `docs/interfaces/chat.md`  
> **Versão:** 1.1  
> **Data:** 2026-10-13  
> **Status:** Chat, gestão de equipe e troca de workspace implementados; verificações e homologações parciais preservam suas datas. A revisão atual descreve o código sem reexecutar testes de mídia ou dispositivos.
> **Relacionado:** [Chat e Multiatendimento](../modulos/chat-multiatendimento.md)

## 1. Objetivo

Descrever a experiência visual e de navegação do módulo de chat na versão inicial do WhatsApp (UAZAPI), para orientar frontend web desktop/mobile, design e QA. O aplicativo deve ser **familiar a quem usa WhatsApp**, mas com identidade visual própria e recursos de multiatendimento.

**Separação de responsabilidades:** este documento define experiência e interface; autorizações, regras de atribuição, status e integração são definidos na [especificação funcional de chat](../modulos/chat-multiatendimento.md). Uma interação visível não dispensa autorização no backend.

**Stack aprovada:** Vue 3 + Inertia.js no frontend web. Vue Flow é usado pelo editor de fluxos implementado. O frontend Inertia foi migrado para Vue nesta etapa.

**Prioridade aprovada:** chat web com adaptação para celular é a primeira entrega. Desktop e mobile usam a mesma aplicação Vue 3 + Inertia, com navegação adaptada ao dispositivo. Não haverá frontend mobile independente. Instalação como PWA é uma proposta opcional ainda não aprovada.

## 2. Decisões aprovadas e referência atual

| ID    | Decisão                                                                                                                                                                             | Estado                                                                |
| ----- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| UI-01 | Desktop com **quatro áreas horizontais**: menu do produto, lista de conversas, chat ativo e dados do contato.                                                                       | **Aprovada**                                                          |
| UI-02 | Menu lateral desktop recolhível: **ícones no modo colapsado** e **ícones com textos no modo expandido**.                                                                            | **Aprovada; navegação básica implementada**                           |
| UI-03 | Menu no mobile em **drawer** (painel deslizante).                                                                                                                                   | **Aprovada; pendente de refinamento**                                 |
| UI-04 | Decisão inicial: dois seletores com atribuição e status, incluindo Concluídos.                                                                                                      | **Aprovada em 12/10; diferenças da tela atual na seção 3.2**          |
| UI-05 | Painel de contato com **edição direta para campos simples** e **modal para ações/edições maiores**.                                                                                 | **Aprovada**                                                          |
| UI-06 | Mobile com **uma tela por vez**: lista → conversa em tela cheia → perfil pelo ícone à direita do cabeçalho.                                                                            | **Atualizada em 16/10/2026**                                                          |
| UI-07 | Interações e aparência do chat inspiradas no **WhatsApp Web** (desktop) e no **WhatsApp móvel** (app), sem copiar a identidade visual ou marca.                                     | **Aprovada**                                                          |
| UI-08 | Perfil em coluna fixa de **290 px** no desktop ao selecionar outra conversa; chat mantém largura mínima de 360 px no grid desktop, com adaptação do perfil quando falta espaço.     | **Aprovada em 12/10/2026**                                            |
| UI-09 | Fotos de lead na lista, cabeçalho e perfil, com iniciais como fallback.                                                                                                             | **Aprovada em 12/10/2026**                                            |
| UI-10 | Prévia imediata de imagem enviada, com estado e conciliação com a UAZAPI.                                                                                                           | **Aprovada em 12/10/2026**                                            |
| UI-11 | Configuração e conexão do WhatsApp em Configurações, restritas a administradores.                                                                                                   | **Aprovada em 12/10/2026**                                            |
| UI-12 | Mensagem de voz pelo microfone: gravar, parar, ouvir, enviar ou descartar; até 2 minutos e 10 MB.                                                                                   | **Aprovada em 12/10/2026; implementada, aceite operacional pendente** |
| UI-13 | Bolinha verde clara abaixo do horário para conversas não lidas; leitura compartilhada ao visualizar as mensagens mais recentes. Cabeçalho e compositor fixos, rolagem no histórico. | **Aprovada em 12/10/2026; implementada localmente**                   |

## 3. Desktop — composição da tela

A partir de 1024 px, a composição usa lista de 280 px, conversa de no mínimo 360 px e perfil de 290 px. Quando a área de conteúdo não comporta as três regiões, o perfil passa a sobreposição; a preferência de recolhimento é independente. Abaixo de 1024 px, usa-se uma região por vez.

Da esquerda para a direita:

```text
┌────────────┬──────────────────────┬────────────────────────────────┬─────────────────────┐
│ Navegação  │ Lista de conversas   │ Conversa ativa                 │ Dados do contato    │
│ do produto │ Busca / seletores       │ Cabeçalho / mensagens / envio  │ CRM / edição rápida │
│ (expansível)│                     │                                │                     │
└────────────┴──────────────────────┴────────────────────────────────┴─────────────────────┘
```

### 3.1 Coluna 1 — Navegação

- Posição à esquerda.
- **Colapsada:** apenas ícones.
- **Expandida:** ícones e respectivos rótulos.
- Os destinos (Chat, Contatos, Fluxos etc.) serão detalhados ao especificar cada módulo; não considerar todos implementados na primeira versão.
- O menu deve preservar o **workspace atual** e permitir a troca entre workspaces autorizados. O seletor fica junto ao usuário no rodapé do menu compartilhado, inclusive dentro do drawer no celular. A localização no cabeçalho mobile foi uma decisão anterior, não a apresentação do código atual.

### 3.2 Coluna 2 — Lista de conversas

- Exibe as conversas do **workspace selecionado** que o usuário tem permissão para visualizar.
- Busca por nome e telefone e botão de filtros aparecem na mesma linha. O cabeçalho visual da lista foi removido; falhas de conexão aparecem em aviso próprio.
- O painel de filtros reúne atribuição (**Todos**, **Meus chats**, **Não atribuídos**), **Atendimentos abertos** e etiquetas do contato. Abertos inclui `open` e `in_progress`. Várias etiquetas usam correspondência por qualquer uma; os grupos de critérios e a busca se combinam.
- Os filtros são aplicados na API antes do limite e da paginação e preservam a autorização por papel, atribuição e `can_view_unassigned`, descrita em Chat e multiatendimento §4; **Todos** não amplia esse conjunto. O backend ainda aceita `status=completed`, embora Concluídos não apareça no painel.
- Selecionar uma conversa atualiza a área central, mantendo a navegação no desktop.

### 3.3 Coluna 3 — Conversa

- Cabeçalho do atendimento, histórico e área de composição/ações.
- Cabeçalho e compositor permanecem visíveis dentro da altura da tela; somente o histórico rola. No celular, a altura disponível acompanha o teclado e as áreas seguras.
- Ao entrar, mostrar as mensagens mais recentes. Atualizações acompanham o final somente enquanto o atendente já está nele; caso contrário, manter a posição e oferecer **↓ Novas mensagens**.
- Marcar como lida somente com o histórico carregado, as mensagens mais recentes visíveis e a conversa na aba ativa.
- Reproduzir padrões de familiaridade do WhatsApp: mensagens recebidas e enviadas visualmente distintas, linha do tempo, anexos e interação com mensagens, conforme capacidades **confirmadas** da UAZAPI.
- Exibir imagens com proporção preservada, legenda e ampliação. Imagens enviadas aparecem de imediato com estado de envio e são conciliadas por identificador; timeout fica pendente sem reenvio automático.
- Renovar pelo backend uma URL de mídia indisponível; indicar quando a UAZAPI não recuperar o arquivo.
- Conversa fora do atendimento humano segue o processamento normal previsto para palavras-chave e fluxos. O compositor permanece visível para quem pode assumir a conversa; o primeiro envio abre e atribui ao remetente. A abertura sem resposta fica em **Perfil do contato → Atendimento**. Conversas atribuídas a outra pessoa bloqueiam o envio até uma troca explícita de responsável.
- Anexos iniciais: **documento, imagem e vídeo**. A gravação de mensagem de voz pelo microfone foi aprovada separadamente e implementada conforme a seção 3.5; selecionar arquivos de áudio permanece fora desta entrega.
- Finalizar fica na seção **Atendimento** do perfil para o responsável ou administrador; notas internas permanecem separadas no perfil.
- Exibir as **notas internas** no perfil do contato, separadas das mensagens, sem envio à UAZAPI. O formulário também aparece no perfil mobile; sua presença diverge do recorte inicial, como registrado em [Interface mobile](mobile.md#3-notas-decisão-inicial-e-implementação-atual).
- Ao consultar mensagens antigas, explicar histórico indisponível ou recuperação pendente, sem prometer acesso permanente ou completo. O histórico completo depende da UAZAPI e sua retenção limitada; prévias e conteúdo temporário seguem o [contrato de tempo real](../operacao/chat-realtime.md). A tela apresenta aviso de retenção e de indisponibilidade, sem garantir sete dias completos em todo cenário.
- Quem pode ver a conversa pode usar **Assumir para mim** no perfil, inclusive quando há outro responsável. Administradores também podem escolher outro membro do workspace para atribuir.

- Ao finalizar, atualizar o status e liberar o responsável ativo, preservando o último atendente e o histórico. **Abertos** inclui **Aberto** e **Em atendimento**, conforme o backend atual. Os seletores de atribuição e status combinam critérios.

### 3.4 Coluna 4 — Contato

- Mostra os dados do contato vinculados ao workspace da conversa.
- Exibir foto no perfil e cabeçalho, além da miniatura na lista. A foto do cabeçalho é apenas identificação; um ícone à direita abre o perfil. Consultar fotos sob demanda e manter URL em cache por 15 minutos; usar iniciais sem foto.
- **Campos simples:** permitir edição diretamente no painel.
- **Ações ou alterações maiores:** abrir modal.
- Campos editáveis no perfil: **nome, email e CPF**.
- Exibir somente **Notas internas** vinculadas à conversa, com autor e data. O campo legado de notas do contato permanece no banco, mas não aparece na interface nem é enviado ao salvar o perfil.

### 3.5 Gravação e reprodução de voz

- Microfone no compositor, disponível somente para quem pode responder e com WhatsApp conectado/provedor disponível. Pedir acesso apenas após clique; requer HTTPS ou outro contexto seguro permitido pelo navegador.
- Fluxo: **gravar → parar → ouvir → enviar ou descartar**. Mostrar contador e controles Parar/Descartar; ao atingir dois minutos, encerrar na prévia, sem enviar automaticamente.
- Prévia com player, Enviar áudio e Descartar. Preservar o texto já digitado; o áudio é enviado separadamente e sem legenda. Bloquear texto/anexos enquanto grava, prepara a prévia ou envia.
- Gravações menores que um segundo ou acima de 10 MB não são enviadas. Estouro de tamanho descarta o buffer e explica que deve ser feita uma gravação menor.
- Ao trocar conversa/workspace, sair da página ou perder autorização/conexão, parar o microfone e descartar gravação/prévia. Uma permissão que chegar depois do cancelamento libera imediatamente as trilhas, sem começar a gravar.
- Informar permissão negada, ausência de microfone, contexto inseguro, formato incompatível e interrupção da gravação. Desktop e mobile usam os mesmos componentes.
- Mensagens recebidas e enviadas usam player HTML nativo, sem autoplay. Mostrar duração quando disponível e **Enviando**, **Aguardando confirmação** ou **Falhou**. A prévia local aparece imediatamente após enviar.
- Áudio do histórico sem URL oferece **Carregar áudio**; o backend verifica a conversa antes de obter o MP3. Uma URL que falhar pode ser renovada uma vez. **Áudio indisponível** é distinto de falha de envio.
- Resultado incerto não oferece reenvio automático; consultar histórico por identificador. Conteúdo e prévias permanecem somente em memória no navegador; arquivos do backend são temporários.
- Fora desta entrega: anexar áudio existente, transcrição, pausa/retomada, edição, waveform personalizada e visualização única.

### 3.6 Equipe e workspaces

- Em **Configurações → Equipe**, administradores podem criar uma conta com nome, email e senha inicial, e marcar permissões para conversas não atribuídas e edição de fluxos.
- Se o email já existir, permitir o vínculo apenas com o email, sem exigir nome ou senha inicial e sem alterar os dados da conta; rejeitar se ela já fizer parte da equipe.
- O papel operacional básico é atribuído internamente às novas associações. O formulário não oferece seleção de papel; as permissões aparecem como checks individuais.
- A troca de workspace fica no rodapé do menu compartilhado, junto ao usuário, no desktop e no drawer mobile.
- O seletor lista apenas workspaces já vinculados à conta; a troca atualiza o workspace ativo e abre o chat correspondente.

## 4. Mobile — navegação

Abaixo de **1024 px**, lista, conversa e perfil ocupam visualizações separadas em toda a largura disponível. O menu geral abre em drawer de 280 px, limitado a 88% da janela; o seletor de workspace fica no rodapé desse menu.

```text
Lista de conversas → selecionar conversa → Conversa
Conversa → ícone à direita do cabeçalho → Perfil do contato
Perfil → Voltar → Conversa → Voltar → Lista
```

O cabeçalho mostra estado e responsável e oferece o ícone do perfil. O compositor fica visível quando a conversa está sem responsável ou atribuída ao usuário; texto, anexos e áudio seguem a mesma regra de primeiro envio. O perfil reúne abertura, atribuição e finalização na seção Atendimento, acima dos dados e controles de automação/Sequência.

As antigas faixas de 700/950 px foram substituídas pelo breakpoint de 1024 px. O recorte inicial sem criação de notas e a previsão de Transferir em menu são preservados como histórico em [Interface mobile](mobile.md); não descrevem a tela atual.

## 5. Diretrizes visuais e de interação

**Aprovadas:**

- Priorizar familiaridade do chat com o WhatsApp para reduzir o treinamento da equipe.
- Manter uma identidade de produto própria, sem reproduzir marca, logotipo ou elementos proprietários de identidade do WhatsApp.
- Dar clareza à distinção entre mensagens enviadas ao cliente e **notas internas**.
- Preservar coerência de funcionamento entre desktop e celular na mesma aplicação web, adaptando a navegação ao dispositivo.

**Propostas técnicas — não aprovadas individualmente:**

- Usar componentes reutilizáveis de interface entre visualizações sempre que a stack permitir.
- Proteger estado/rascunhos do chat ao alternar entre conversas ou telas, evitando perda acidental de texto.
- Empregar acessibilidade por teclado, rótulos acessíveis e estados visíveis de carregamento/erro.
- Buscar o histórico pelo backend/adaptador. No modo de tempo real, prévias e conteúdo temporário criptografado seguem o [contrato de dados](../operacao/chat-realtime.md), sem cópia completa do histórico.

## 6. Estado dos pontos de experiência

As linhas distinguem funcionamento atual de validação operacional; não representam um novo levantamento de implementação.

| ID    | Ponto                                                                            | Observação                                                                                                                                                                                 |
| ----- | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| UX-01 | Perfil desktop                                                                   | Largura de 290 px, recolhimento manual e sobreposição adaptativa implementados; não há redimensionamento livre.                                                                            |
| UX-02 | Ordenação e conteúdo da lista                                                    | Busca por nome e telefone aprovada e implementada; validar disponibilidade com o servidor contratado.                                                                                      |
| UX-03 | Indicadores de entrega, digitação e prévias                                      | Não lidas e leitura compartilhada implementadas; demais indicadores dependem de validação da UAZAPI.                                                                                       |
| UX-04 | Anexos iniciais                                                                  | Documento, imagem e vídeo aprovados; definir controles/limites e validar suporte operacional.                                                                                              |
| UX-05 | Localização do seletor de workspace                                              | No rodapé do menu compartilhado, inclusive no drawer mobile; a localização anterior no cabeçalho da lista não corresponde à tela atual.                                                    |
| UX-06 | Dois seletores suspensos                                                         | Comportamento atual: atribuição Todos/Meus chats/Não atribuídos e status Todos/Abertos; Abertos inclui aguardando responsável e em atendimento. Decisão anterior preservada na seção 3.2.  |
| UX-07 | Edição de contato                                                                | Nome/e-mail opcionais até 255 caracteres, e-mail válido e CPF opcional até 14 caracteres, sem dígitos verificadores nesse endpoint; notas pertencem à conversa. Ver multiatendimento §6.4. |
| UX-08 | Tratamento visual de notas internas, atribuições, transferências e encerramentos | Validar no protótipo.                                                                                                                                                                      |
| UX-09 | Notificações, atalhos, estados vazios e erros de sincronização                   | Especificar para web e mobile.                                                                                                                                                             |
| UX-10 | Estados na lista                                                                 | Abertos contém Aberto e Em atendimento; chats sem atendimento humano e concluídos podem aparecer em Todos, conforme acesso e atribuição.                                                   |
| UX-11 | Instalação opcional                                                              | Mesma aplicação web responsiva confirmada; decidir depois sobre PWA, notificações e eventual distribuição em lojas.                                                                        |
| UX-12 | Configuração WhatsApp                                                            | Página exclusiva de administradores com status, informações da conta, verificação e QR Code.                                                                                               |
| UX-13 | Prévia e recuperação de mídia                                                    | Testar IDs, URLs e expiração no servidor contratado.                                                                                                                                       |

## 7. Critérios de referência de interface

Checks preservam evidências anteriores; não significam nova execução de testes nesta revisão. Os critérios abaixo foram ajustados ao comportamento observado, sem aprovar retroativamente diferenças de produto.

- [x] Estados de gravação, prévia/descarte, limite de dois minutos e escolha WebM/OGG/MP4 cobertos por testes automatizados de frontend.
- [x] Cancelamento por troca de conversa, perda de permissão e desmontagem libera microfone e timers; permissão tardia não inicia gravação.
- [ ] Validar gravação, player e layout em Chrome/Edge, Firefox, Safari e navegadores móveis reais, incluindo permissão negada.
- [x] Conversas não lidas exibem uma bolinha de 7 px abaixo do horário; mensagens recentes visualizadas confirmam leitura para todo o workspace.
- [x] Leitura aguarda confirmação do provedor e usa versão por conversa para preservar novas mensagens concorrentes.
- [x] Cabeçalho e compositor permanecem visíveis; histórico rola separadamente e novas mensagens não deslocam uma leitura antiga.
- [ ] Validar no servidor contratado a operação de marcar chat como lido e a confirmação no WhatsApp.
- [ ] Confirmar no WhatsApp do destinatário que `ptt` chega como mensagem de voz reproduzível no servidor contratado.

- [ ] No desktop, o chat possui quatro áreas identificáveis e o menu alterna entre ícones e ícones + textos.
- [ ] A lista possui busca e dois seletores com as opções atuais **Todos / Meus chats / Não atribuídos** e **Todos / Abertos**, sem expor chats proibidos.
- [ ] No desktop, selecionar uma conversa troca a área central sem navegar para uma página de detalhes separada.
- [ ] No mobile, selecionar uma conversa abre uma tela única, sem tentar manter quatro colunas.
- [ ] No mobile, o ícone à direita do cabeçalho abre o perfil; a foto não funciona como botão.
- [ ] No mobile, o menu do aplicativo abre por drawer.
- [ ] Campos simples definidos como editáveis são alterados no painel; edições maiores usam modal.
- [ ] Desktop mantém perfil de 290 px ou sobreposição adaptativa; abaixo de 1024 px, o perfil ocupa uma visualização própria. Fotos aparecem nos três locais e falhas usam iniciais.
- [ ] Imagem enviada aparece imediatamente e permanece única após conciliação; timeout não repete o envio.
- [ ] Configurações de conexão só podem ser acessadas por administradores; falha do provedor não é apresentada como desconexão confirmada.
- [x] Administradores criam contas ou vinculam contas existentes em Configurações → Equipe; permissões são salvas no vínculo do workspace.
- [ ] O seletor atual fica no rodapé do menu compartilhado, inclusive no drawer mobile, e oferece apenas workspaces vinculados à conta. A validação histórica de localização no cabeçalho mobile não comprova essa disposição atual.
- [ ] Primeiro envio em conversa sem responsável abre e assume; conversa ocupada bloqueia o envio até atribuição explícita, inclusive para administrador.
- [ ] Documento, imagem e vídeo estão disponíveis como anexos iniciais.
- [ ] O painel mostra nome, email, CPF e somente o histórico de Notas internas, com autor e data.
- [ ] Notas internas são distinguíveis das mensagens e nunca aparecem como mensagens enviadas ao WhatsApp.
- [ ] O visual e os fluxos de uso remetem à experiência conhecida do WhatsApp, mantendo identidade própria.

## 8. Manutenção

- As seções **Decisões aprovadas**, **Propostas técnicas** e **Pendências** devem permanecer distintas.
- Mudanças nas regras de negócio precisam ser sincronizadas com `docs/modulos/chat-multiatendimento.md`.
- Ao validar protótipos, incluir links para arquivos de design, capturas e decisões aprovadas.

### Histórico

- **v1.1 — 2026-10-13:** revisão do cadastro: nome e senha exigidos somente para novas contas; identificador mobile de workspace exibido uma única vez.
- **v0.9 — 2026-10-12:** bolinha de não lidas, leitura compartilhada via UAZAPI, controle de concorrência e painel fixo do chat implementados localmente.
- **v1.0 — 2026-10-12:** criação de contas e vínculo de usuários existentes em Equipe; troca de workspace no menu desktop e na lista mobile.

- **v0.7 — 2026-10-12:** perfil fixado em 240 px no desktop e controle de abertura/fechamento disponibilizado também entre 701 e 950 px.
- **v0.8 — 2026-10-12:** painel de perfil definido em 290 px; notas gerais do contato ocultadas; cache de avatares sem foto coberto por 15 minutos e passível de renovação.
- **v0.6 — 2026-10-12:** aprovada configuração WhatsApp para administradores, perfil fixo, avatares de leads e prévia imediata de imagens.

- **v0.5 — 2026-10-12:** esclarecida reutilização da mesma aplicação web em desktop/celular; descartada proposta de frontend mobile independente; PWA permanece opcional.

- **v0.4 — 2026-10-12:** abas substituídas por dois seletores; aprovados campos do contato, anexos e busca; sincronizadas permissões e regra de entrada humana.

- **v0.3 — 2026-10-12:** registrada aceitação do histórico limitado da UAZAPI e necessidade de comunicar indisponibilidade/recuperação no chat.
- **v0.2 — 2026-10-12:** frontend Vue confirmado, prioridade do chat e comportamento visual da liberação do responsável ao finalizar; formato de entrega mobile mantido pendente.
- **v0.1 — 2026-10-12:** estrutura desktop/mobile e decisões visuais aprovadas durante o levantamento.

- **v0.8 — 2026-10-12:** gravação pelo microfone, prévia antes do envio, limite de dois minutos, player e estados de envio implementados; testes locais registrados e aceite operacional de áudio pendente.

## Sequência no painel do contato — 14/10/2026

Seção **Sequência** com participação atual/último resultado, etapa, saldo/data, impedimentos e última transição. Gestão de fluxos com acesso ao contato pode abrir **Adicionar à sequência** em modal; o aviso explica que a participação anterior será encerrada e o fluxo corrente continuará. Não há remoção ou reinício manual nesta versão. Ver [Sequências](../modulos/sequencias.md).
