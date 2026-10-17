# Módulo de Leads — Listagem e ficha do lead

> **Projeto:** chAIt — SaaS de atendimento e automação via WhatsApp  
> **Documento sugerido no repositório:** `docs/modulos/leads.md`  
> **Versão:** 0.1  
> **Data do briefing:** 2026-10-14
> **Estado em 17/10/2026:** não implementado. A comparação com `routes/`, `resources/js/pages/` e `AppNavigation.vue` não encontrou módulo, rotas ou entrada de navegação para Leads; este documento continua sendo briefing funcional, não evidência de implementação.
> **Stack do produto:** Laravel no backend; Vue 3 + Inertia.js na aplicação web  
> **Relacionado:** `docs/modulos/chat-multiatendimento.md`, `docs/interfaces/chat.md`, `docs/modulos/editor-de-fluxos.md` e `docs/planejamento/decisoes.md`.

## 1. Objetivo

Disponibilizar uma área **Leads** simples para localizar contatos, aplicar filtros, abrir a ficha individual e manter os dados utilizados pelo Chat e pelas automações.

**Não criar um segundo cadastro/CRM paralelo.** O módulo deve operar sobre a mesma entidade de contato/lead, as mesmas etiquetas e os mesmos campos personalizados utilizados pelo restante do produto. A distinção entre os termos *lead* (interface) e *contato* (domínio ou tabela existente) é apenas de apresentação, caso o projeto já use `contacts`.

### 1.1. Princípios aprovados

- Entregar somente funções essenciais; não transformar a tela em um CRM completo.
- Abrir a ficha em **página própria**, não em painel lateral nem modal.
- Reutilizar a identidade visual e os componentes da aplicação, com layout claro/neutro e consistência com o Chat.
- Usar a mesma aplicação web no desktop e no celular, com apresentação responsiva.
- Respeitar o isolamento por workspace e as permissões no backend.
- Reutilizar os campos personalizados e seus tipos **já implementados, conforme informado pelo responsável pelo produto**. A estrutura existente deverá ser inspecionada antes de integrar este módulo; não reconstruir o sistema de campos.

## 2. Tela de listagem de Leads

### 2.1. Navegação e composição

- Criar a entrada **Leads** no menu lateral existente.
- A página exibe o título **Leads**, campo de busca, filtros, total de resultados, botão **+ Novo Lead** e tabela de resultados.
- A tabela deve permitir clicar em um lead ou na ação **Abrir ficha** para navegar à página individual daquele lead.
- A aparência deve ser discreta, objetiva e compatível com os componentes já adotados no produto.

### 2.2. Colunas aprovadas

| Coluna | Conteúdo | Observação |
|---|---|---|
| **Lead** | Nome e número de WhatsApp | Ambos na mesma célula; exibir o número mesmo que o nome esteja vazio. |
| **Etiquetas** | Etiquetas atribuídas ao lead | Pode haver mais de uma. |
| **Cadastrado em** | Data de criação do cadastro | Não confundir com a data da última mensagem. |
| **Ação** | **Abrir ficha** | Navegação para a página de detalhes. |

**Não adicionar colunas de campos personalizados nesta versão.**

### 2.3. Busca aprovada

- Buscar por **nome** ou **número de WhatsApp**.
- Não incluir inicialmente busca por CPF, e-mail, texto de mensagens ou conteúdo das notas.
- Combinar a busca com os filtros escolhidos, respeitando o workspace e a permissão do usuário.

### 2.4. Filtros aprovados

1. **Etiquetas:** permitir selecionar uma ou mais etiquetas e a condição **Sem etiqueta**.
2. **Data de cadastro:** permitir selecionar período de criação do lead.

Os filtros se aplicam à lista de leads, não ao status da conversa. **Não adicionar filtros de atendimento humano** nesta tela: o Chat já possui os seus próprios controles.

**Detalhe técnico não decidido em produto:** ao selecionar duas ou mais etiquetas, é preciso definir se o lead deve corresponder a *qualquer uma* ou *todas*. A recomendação inicial é **qualquer uma (OU)**, deixando explícito no componente. A combinação de **Sem etiqueta** com etiquetas selecionadas também deve ter comportamento não ambíguo. Não tratar essas escolhas como aprovação prévia.

### 2.5. Ordenação e paginação aprovadas

- Ordenação inicial: **leads mais recentes primeiro**, pela data de cadastro.
- Permitir inverter para **mais antigos primeiro**.
- Implementar **paginação** no backend; filtros, busca e ordem devem continuar aplicados ao navegar entre páginas.
- Exibir o total de registros encontrados de acordo com a consulta autorizada.

## 3. Ficha individual do Lead

Ao selecionar um registro, abrir uma **rota/página própria** para a ficha. Evitar modais ou painel lateral para a visualização principal.

### 3.1. Estrutura aprovada

| Área | Conteúdo e comportamento |
|---|---|
| **Cabeçalho** | Nome do lead, número de WhatsApp e botão **Abrir conversa**. |
| **Dados do contato** | Nome, WhatsApp, e-mail e CPF. |
| **Etiquetas** | Visualizar, adicionar e remover etiquetas do lead. |
| **Campos personalizados** | Exibir e preencher os campos já definidos no workspace, utilizando a implementação existente. |
| **Observações do contato** | Exibir e editar observações gerais vinculadas ao lead. |

### 3.2. Edição dos campos

- **Edição direta na ficha** para nome, e-mail, CPF, etiquetas, observações e valores dos campos personalizados, desde que o usuário tenha autorização.
- **WhatsApp somente leitura** nesta primeira versão, por servir de identidade de contato e poder estar vinculado a conversas e eventos da integração.
- A ficha deve informar claramente sucesso ou erro ao salvar, sem exibir alterações como persistidas antes da confirmação do backend.
- Campos ausentes devem aparecer de maneira neutra (por exemplo, “Não informado”), sem obrigar seu preenchimento para visualizar a ficha.

### 3.3. Observações x notas internas

**Decisão funcional:** as **observações do lead** são informações gerais do cadastro, distintas das **notas internas do atendimento** que pertencem ao contexto de uma conversa no Chat.

Não misturar automaticamente as duas fontes nem converter observações em mensagens do WhatsApp. As notas internas continuam seguindo as permissões e regras já especificadas no módulo de Chat.

### 3.4. Botão Abrir conversa

- Abrir diretamente a conversa do contato no módulo **Chat**, dentro do mesmo workspace.
- **Não conceder acesso ao Chat** apenas porque o usuário pode visualizar um lead: as permissões de conversa existentes continuam obrigatórias.
- Abrir a ficha ou usar esse botão **não** deve assumir, reabrir ou iniciar um atendimento humano automaticamente.
- Caso o usuário não tenha permissão para aquela conversa, impedir o acesso (incluindo por URL direta ou API).

**Pendência de apresentação:** definir o estado do botão quando não houver conversa disponível, ou quando o usuário não puder visualizá-la. A decisão de produto não autoriza ignorar a restrição.

## 4. Cadastro de novos leads

### 4.1. Origens aprovadas

- **Automático:** um contato novo envia uma mensagem para o WhatsApp do workspace; o sistema cria o cadastro correspondente, reaproveitando a lógica de integração já prevista para o Chat.
- **Manual:** o usuário aciona **+ Novo Lead** na página de listagem.

O cadastro manual **não deve criar um atendimento humano nem disparar automaticamente um fluxo**. O comportamento de automação continua dependente de gatilhos próprios.

### 4.2. Campos do cadastro manual

| Campo | Obrigatoriedade |
|---|---|
| **WhatsApp** | **Obrigatório** |
| Nome | Opcional |
| E-mail | Opcional |
| CPF | Opcional |
| Etiquetas | Opcionais |

Não exigir e-mail ou CPF para cadastrar um lead. Os campos personalizados não são uma exigência obrigatória deste formulário inicial.

### 4.3. Duplicidade e identidade

- Impedir **duplicidade do mesmo número de WhatsApp dentro do mesmo workspace**.
- Normalizar e validar o número antes da comparação, usando uma forma canônica consistente com o identificador do canal; evitar comparar somente a aparência formatada na tela.
- Se um lead criado manualmente enviar posteriormente a primeira mensagem, **reutilizar o cadastro existente** em vez de criar outro.
- O mesmo telefone **pode pertencer a contatos distintos em workspaces diferentes**; não impor unicidade global.
- Tratar concorrência entre cadastro manual e recebimento simultâneo do webhook, evitando duplicação mesmo em duas requisições paralelas.

**Atenção de integração:** o WhatsApp pode fornecer IDs externos e formatos que exigem reconciliação cuidadosa; confirmar os formatos reais da UAZAPI e não confiar apenas em uma remoção ingênua de caracteres do telefone.

## 5. Etiquetas

### 5.1. Funcionalidades aprovadas

- Exibir na ficha as etiquetas já atribuídas ao lead.
- Usar um **seletor pesquisável** para localizar e adicionar etiquetas existentes.
- Permitir **múltiplas etiquetas por lead**.
- Permitir **remover uma etiqueta** da ficha.
- Permitir **criar uma etiqueta diretamente no seletor** quando o usuário tiver a permissão específica.
- As etiquetas são isoladas por workspace.
- Não permitir etiquetas de mesmo nome duplicadas no mesmo workspace.
- A listagem permite filtrar por etiquetas, inclusive **Sem etiqueta**.

**Recomendação técnica:** comparar nomes normalizados (por exemplo, desconsiderando espaços extras e diferenças de caixa) para bloquear duplicidade; definir a regra exata conforme o mecanismo de etiquetas existente. Não criar uma segunda tabela de etiquetas.

### 5.2. Criação versus aplicação

**Criar novas etiquetas** e **aplicar/remover etiquetas existentes** são ações distintas:

- Quem tem **Editar leads** pode aplicar e remover etiquetas existentes em leads que está autorizado a editar.
- Quem tem **Criar etiquetas** pode cadastrar novas etiquetas; a autorização para alterar o vínculo de um lead continua sendo controlada por **Editar leads**.
- O administrador do workspace tem essas permissões por padrão.

Não presumir que os usuários possam renomear ou excluir etiquetas globais; essas ações não foram definidas para este escopo.

## 6. Campos personalizados

- **Reutilizar integralmente** os campos personalizados e tipos de dados já implementados no projeto, conforme informado pelo responsável pelo produto.
- Não restringir o módulo a campos do tipo texto e **não criar outra estrutura de campos**.
- Exibir na ficha os campos disponíveis para leads conforme o mecanismo existente.
- Quem possui **Editar leads** pode preencher/alterar os valores autorizados dos campos.
- A permissão **Criar campos personalizados**, definida pelo administrador, controla quem pode criar novos campos para o workspace.
- Ao criar um campo, ele pertence à estrutura de campos do workspace; **o valor pertence individualmente a cada lead**.
- O Editor de Fluxos deve reutilizar exatamente esses mesmos campos e valores.

**Verificação antes de desenvolver:** inspecionar os nomes reais de tabelas, models, validações, tipos, permissões e componentes já existentes. Não assumir que a documentação antiga — que tratava os campos personalizados como futuros — reflita o estado atual da implementação.

## 7. Permissões e isolamento

### 7.1. Permissões aprovadas

O administrador configura permissões **por usuário e por workspace**, independentemente das permissões específicas do Chat.

| Permissão | Alcance |
|---|---|
| **Visualizar leads** | Acessar a listagem completa de leads do workspace e abrir suas fichas. |
| **Editar leads** | Editar dados, etiquetas, observações e valores de campos personalizados de leads que pode visualizar. |
| **Criar etiquetas** | Cadastrar novas etiquetas no workspace. |
| **Criar campos personalizados** | Cadastrar novos campos personalizados para o workspace. |

- O **administrador** possui as quatro permissões por padrão.
- Para outros membros, o administrador pode habilitar ou não cada permissão no cadastro/vínculo do usuário ao workspace.
- Visualizar leads **não** implica visualizar conversas; editar leads **não** implica responder no Chat.
- A autorização precisa ser aplicada no **Laravel** em consultas e operações. Ocultar botões no Vue não substitui autorização.
- Todos os identificadores de lead, etiqueta e campo devem ser validados quanto ao workspace ativo, inclusive em buscas, edições e requisições diretas à API.

### 7.2. Integração com permissões do Chat

- A edição de dados do lead pelo painel de contato do Chat deve respeitar **Editar leads**, evitando contornar a permissão usando outra tela.
- As permissões de conversa continuam controlando acesso ao histórico e às operações de atendimento.
- Os dados mínimos necessários para identificar a pessoa em uma conversa autorizada (por exemplo, nome e número) não devem ser ocultados automaticamente só porque a pessoa não tem acesso à **listagem completa** de leads; essa fronteira deverá ser validada com as regras atuais do Chat.

### 7.3. Regra operacional recomendada, ainda não aprovada separadamente

**Cadastro manual:** não foi criada uma quinta permissão específica de “Criar leads”. Como padrão de implementação a validar, vincular **+ Novo Lead** a **Editar leads**, exigindo também acesso ao módulo de Leads. Evitar atribuir esse comportamento como decisão confirmada sem verificar o padrão de autorização existente.

## 8. Experiência visual e responsividade

### 8.1. Desktop

- Menu lateral existente, com item **Leads**.
- Listagem em página própria, com ferramentas de busca/filtro e tabela de quatro colunas.
- Ficha em página própria, com cabeçalho e seções bem separadas; campos simples editáveis sem abrir modais grandes.
- Aproveitar componentes e tokens visuais já definidos para o produto; não incorporar uma nova biblioteca visual sem necessidade.

### 8.2. Celular

- Usar **a mesma aplicação Vue 3 + Inertia.js**, adaptada à largura do dispositivo.
- Preservar pesquisa, filtros, acesso ao cadastro manual e à ficha.
- Priorizar leitura do nome e número, sem exigir que o usuário percorra uma tabela larga para localizar um lead.
- A ficha mantém as seções essenciais com organização vertical; **Abrir conversa** deve permanecer acessível.

**Solução técnica sugerida, não fixada pelo briefing:** transformar linhas em cartões compactos no celular ou usar um arranjo de células empilhadas. A escolha deve obedecer ao design system e ser validada na implementação.

## 9. Fora do escopo aprovado para esta versão

- Importação ou exportação de leads por planilha.
- Exclusão de leads.
- CRM completo com funil, negócios, tarefas, campanhas ou relatórios avançados.
- Campos personalizados como colunas da listagem.
- Filtros de estado do atendimento humano na tela de Leads.
- Busca em mensagens do WhatsApp, notas internas, CPF ou e-mail pela barra principal de busca.
- Alteração manual do número de WhatsApp de um cadastro existente.
- Criação de atendimento humano pelo simples ato de cadastrar ou abrir a ficha.
- Novos sistemas paralelos de etiquetas, campos personalizados, notas ou histórico de mensagens.

Estas funcionalidades não devem ser incluídas por iniciativa de implementação sem nova aprovação.

## 10. Diretrizes técnicas para implementação

> Esta seção contém **orientações de engenharia**, não nomes definitivos de tabelas, endpoints ou migrations.

1. **Aproveitar domínio existente:** reutilizar lead/contato, etiquetas e campos personalizados; inspecionar o código antes de alterar o esquema.
2. **Autorização por Policies/Gates:** separar ver/editar/criar etiqueta/criar campo e aplicar escopo por workspace a todos os endpoints.
3. **Consultas paginadas no servidor:** buscas e filtros executados no backend com índices adequados para workspace, nome/número e data de cadastro, conforme a estrutura existente.
4. **Busca de telefone coerente:** normalização consistente para busca, identidade, deduplicação e integração com UAZAPI.
5. **Proteção de dados pessoais:** e-mail, CPF, observações e valores personalizados somente para usuários autorizados; não registrar esses conteúdos indiscriminadamente em logs.
6. **Proteção de concorrência:** garantir unicidade efetiva por workspace/identidade canônica na camada de persistência e tratar conflitos de cadastro de forma previsível.
7. **Integração de dados:** alterações na ficha devem refletir no painel do Chat e ser lidas pelos blocos dos fluxos; evitar cópias divergentes.
8. **Separação de histórico:** não salvar mensagens do WhatsApp no módulo de Leads; manter a política existente de histórico apenas na UAZAPI.
9. **Validação:** utilizar validações já existentes para e-mail, CPF, número WhatsApp e tipos de campos personalizados; não introduzir regras incompatíveis por suposição.
10. **UX confiável:** indicar carregamento, lista vazia, erros de busca/salvamento, validação e ausência de autorização sem vazar dados de outros workspaces.

## 11. Critérios mínimos de aceite

### 11.1. Listagem

- [ ] Item **Leads** disponível na navegação para usuários autorizados.
- [ ] Listagem apresenta exatamente as quatro colunas aprovadas: **Lead**, **Etiquetas**, **Cadastrado em**, **Ação**.
- [ ] Célula **Lead** mostra nome e WhatsApp.
- [ ] Busca localiza contatos por nome e WhatsApp, sem acessar dados de outros workspaces.
- [ ] Filtragem por uma ou várias etiquetas e por **Sem etiqueta** está disponível.
- [ ] Filtro de período usa a **data de cadastro**.
- [ ] Ordem padrão: mais recentes; opção para mais antigos.
- [ ] Resultados paginados, com total e manutenção de busca/filtros/ordem entre páginas.
- [ ] Clicar em **Abrir ficha** leva à página individual do lead.

### 11.2. Ficha e cadastro

- [ ] Ficha possui dados de contato, etiquetas, campos personalizados e observações.
- [ ] Nome, e-mail, CPF, etiquetas, observações e valores personalizados podem ser editados por usuário autorizado.
- [ ] Número de WhatsApp existente aparece como **somente leitura**.
- [ ] Observações do contato não são confundidas com notas internas da conversa.
- [ ] **Abrir conversa** respeita permissões do Chat e não abre atendimento humano automaticamente.
- [ ] **+ Novo Lead** permite cadastro manual com WhatsApp obrigatório e demais campos aprovados opcionais.
- [ ] Recebimento de um novo contato WhatsApp pode criar o lead automaticamente.
- [ ] Cadastro manual e criação por webhook não geram contatos duplicados para a mesma identidade no workspace.
- [ ] O mesmo telefone em workspaces distintos continua independente.

### 11.3. Etiquetas, campos e permissões

- [ ] É possível aplicar/remover múltiplas etiquetas existentes na ficha.
- [ ] Usuário autorizado pode criar etiqueta pelo seletor; duplicatas no workspace são bloqueadas.
- [ ] Campos personalizados e tipos existentes são reutilizados; os valores são específicos de cada lead.
- [ ] **Visualizar leads**, **Editar leads**, **Criar etiquetas** e **Criar campos personalizados** são configuráveis por usuário/workspace.
- [ ] Administrador tem essas permissões por padrão.
- [ ] Usuário sem **Editar leads** não edita dados por meio da ficha nem do painel de contato no Chat.
- [ ] Usuário sem **Criar etiquetas** ou **Criar campos personalizados** não usa endpoints de criação, ainda que tente chamar a API diretamente.
- [ ] Permissão para visualizar leads não concede acesso indevido à conversa.
- [ ] Interface funciona em desktop e celular sem criar um frontend móvel paralelo.

## 12. Pontos não decididos e validações necessárias

Estes pontos não impedem a documentação da proposta, mas **não devem ser tratados como regras de produto aprovadas**:

1. Semântica de seleção de múltiplas etiquetas: **OU** ou **E**; recomendação inicial: **OU**.
2. Comportamento exato de **Sem etiqueta** quando combinado com outras etiquetas.
3. Tratamento visual do botão **Abrir conversa** sem conversa disponível ou sem acesso.
4. Permissão exigida para **criar lead manualmente**: recomendação inicial de aproveitar **Editar leads**, sem nova permissão.
5. Fronteira de dados mínimos exibidos no painel do Chat quando o usuário não tem acesso à listagem completa de Leads.
6. Forma canônica e reconciliação de identidade do WhatsApp com os identificadores reais da versão contratada da UAZAPI.
7. Confirmação técnica da estrutura já implementada para etiquetas, campos personalizados e seus tipos; a alegação de implementação veio do responsável pelo produto e não foi auditada neste documento.
8. Comportamento de salvamento de edições concorrentes da mesma ficha e da criação simultânea de cadastro por usuário/webhook.

## 13. Registro das decisões desta rodada

| ID | Decisão | Situação |
|---|---|---|
| LD-01 | Criar menu/tela **Leads**, com listagem e ficha em **página própria**. | **Aprovada** |
| LD-02 | Busca por nome/WhatsApp; filtros por múltiplas etiquetas, **Sem etiqueta** e período de cadastro. | **Aprovada** |
| LD-03 | Ficha com nome, WhatsApp, e-mail, CPF, observações, etiquetas e campos personalizados; edição direta; WhatsApp somente leitura. | **Aprovada** |
| LD-04 | **Abrir conversa** leva ao Chat e respeita permissões independentes. | **Aprovada** |
| LD-05 | Permissões independentes **Visualizar leads** e **Editar leads**, configuradas pelo administrador por usuário/workspace. | **Aprovada** |
| LD-06 | Cadastro **automático** por mensagem recebida e **manual** via **+ Novo Lead**; WhatsApp obrigatório; demais dados opcionais; impedir duplicatas no mesmo workspace. | **Aprovada** |
| LD-07 | Etiquetas pesquisáveis, múltiplas, criadas pela ficha; permissão própria para **Criar etiquetas**. | **Aprovada** |
| LD-08 | Administrador pode habilitar separadamente **Criar campos personalizados** por usuário/workspace. | **Aprovada** |
| LD-09 | Reutilizar os **tipos e campos personalizados já implementados**, sem criar modelo paralelo. | **Confirmada pelo responsável quanto ao estado atual; requer inspeção técnica** |
| LD-10 | Lista com **quatro colunas**: Lead, Etiquetas, Cadastrado em e Ação; ordenação por data e paginação. | **Aprovada** |
| LD-11 | Não implementar agora importação por planilha, exclusão ou CRM avançado. | **Escopo inicial aceito durante a discussão** |

## 14. Histórico de versões

- **v0.1 — 2026-10-14:** primeira consolidação das decisões aprovadas sobre lista, filtros, ficha, edição, cadastro manual/automático, etiquetas, campos existentes, permissões, responsividade e escopo mínimo.

---

**Orientação para o desenvolvedor:** incluir este arquivo em `docs/modulos/leads.md`, referenciá-lo no índice de `docs/README.md` quando incorporado ao repositório e implementar sobre as entidades já existentes. Não interpretar exemplos técnicos ou pendências como novos requisitos aprovados.
