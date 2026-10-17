# CHAIT — Importação, exportação e compartilhamento de fluxos em JSON

> **Documento:** especificação funcional e diretrizes técnicas
> **Data:** 15/10/2026
> **Status:** decisões aprovadas e incorporadas à implementação local; referência vigente em `docs/modulos/importacao-exportacao-fluxos.md`
> **Documento vigente:** [Importação e exportação de fluxos](../../modulos/importacao-exportacao-fluxos.md)
> **Relacionado:** `docs/modulos/editor-de-fluxos.md` e `docs/modulos/assistente-ia-fluxos.md`

## 1. Objetivo

Permitir que um usuário construa ou aperfeiçoe uma automação com uma IA externa (ChatGPT, Claude, Gemini etc.), obtenha um arquivo JSON compatível com o CHAIT e o **importe dentro do editor visual de um fluxo já aberto**, substituindo seu rascunho. O mesmo editor permite **exportar um fluxo individual** para edição externa ou compartilhamento.

O JSON é um **formato de transporte**, não um segundo motor de automações. O fluxo importado continua sendo editado, validado, publicado e executado pelo mecanismo existente do CHAIT.

### 1.1 Princípios

- **Simplicidade da experiência:** importar, confirmar e continuar editando no mesmo editor.
- **Fonte única de verdade:** catálogo e regras de blocos existentes no projeto; não manter dois contratos de negócio independentes.
- **Segurança:** nunca importar credenciais, executar o arquivo diretamente ou ativar automaticamente um fluxo.
- **Isolamento de workspace:** todas as associações/criações respeitam o workspace do fluxo aberto.
- **Rascunhos incompletos são válidos como trabalho em andamento; publicação exige configuração suficiente.**

## 2. Escopo da primeira entrega

**Incluído:**

- Importação via texto JSON colado ou arquivo `.json` selecionado, **somente dentro do editor de um fluxo**.
- Substituição integral do **rascunho desse fluxo**, mediante confirmação.
- Validação determinística, com mensagens específicas e ação **Copiar erros**.
- Salvamento automático do rascunho no ato da importação.
- Exportação do **rascunho salvo** ou da **versão publicada** do fluxo aberto.
- Um fluxo por arquivo JSON.
- Guia Markdown para o usuário baixar e anexar a uma IA externa.
- Criação automática de etiquetas e campos personalizados ausentes, com validação de compatibilidade.
- Organização visual automática dos blocos quando o arquivo não fornecer posições utilizáveis.

**Fora do escopo inicial:**

- Importar ou exportar pela biblioteca/listagem de fluxos.
- Importar vários fluxos ou pacotes em um único arquivo.
- Importar trechos para mesclar com blocos existentes.
- Importar arquivos de mídia junto do JSON.
- Criar automaticamente membros/atendentes ou fluxos de destino.
- Prévia visual ou tela-resumo da importação.
- Backup automático ou ação específica **Desfazer importação**.
- Conversão automática de versões antigas incompatíveis.
- Correção de JSON por chamada à IA durante a importação.
- Marketplace público de modelos.

## 3. Local das ações e fluxo de uso

### 3.1 Importação

1. O usuário **já está dentro do editor** de um fluxo criado ou existente.
2. Seleciona **Importar JSON**.
3. Cola o texto ou envia um arquivo `.json`.
4. O backend verifica formato, versão, tipos de bloco, ligações e demais requisitos mínimos de importação.
5. **Se não puder importar:** exibe erros específicos, permite **Copiar erros** e **não modifica** o rascunho nem cria recursos.
6. **Se puder importar:** apresenta somente a confirmação de substituição — sem prévia visual nem resumo de quantidades.
7. Confirmada a ação, o backend verifica novamente a revisão do rascunho, resolve/cria os recursos elegíveis e substitui e **salva automaticamente** o rascunho.
8. O editor atualiza seu próprio canvas, sem redirecionar o usuário a outra tela. O fluxo segue não publicado até que o usuário decida publicá-lo pelo processo normal.

**Texto de confirmação sugerido:**

> **Substituir rascunho?**
>
> A importação substituirá todos os blocos, configurações e conexões do rascunho atual. Essa ação não poderá ser desfeita. A versão publicada, se existir, não será alterada.
>
> **Cancelar** | **Confirmar importação**

**Não criar cópia de recuperação específica para a importação.** A confirmação é a proteção de produto escolhida.

### 3.2 Exportação

1. O usuário abre o editor de um fluxo individual.
2. Seleciona **Exportar JSON**.
3. Se houver tanto rascunho salvo quanto versão publicada, escolhe qual deseja exportar. Caso não exista versão publicada, essa opção não aparece ou fica desabilitada.
4. O CHAIT gera **um único arquivo `.json`** com o conteúdo da versão selecionada.
5. Exibe um aviso simples antes da exportação:

> O arquivo exportado pode conter mensagens e instruções internas do fluxo. Revise o conteúdo antes de compartilhá-lo.

O aviso **não bloqueia** a exportação. Não haverá análise automática de conteúdo confidencial na primeira versão.

A exportação do rascunho **não depende de estar pronto para publicação**; blocos incompletos podem ser exportados. A referência é o **rascunho efetivamente salvo**, e não alterações locais ainda não salvas.

## 4. Identidade do fluxo e efeito da importação

- A importação ocorre **dentro** de um fluxo existente no workspace atual.
- O fluxo **mantém seu ID, nome e vínculo organizacional** no CHAIT. O nome que constar no arquivo JSON é informativo e **não renomeia** o fluxo aberto.
- O conteúdo do rascunho (nós, configurações e conexões) é **substituído por completo**; não há mesclagem.
- A versão publicada existente **permanece inalterada**, inclusive para execuções em curso; a importação não publica nem ativa o novo conteúdo.
- Gatilhos configurados na conexão WhatsApp (boas-vindas, resposta padrão, palavras-chave) **não fazem parte da exportação/importação** e não são alterados por ela.
- Alterações pendentes na aba do navegador não devem prevalecer silenciosamente sobre o rascunho importado: ao confirmar, o usuário está substituindo o conteúdo atual daquela edição.

## 5. Contrato público de transporte JSON

O formato será um **JSON próprio do CHAIT**, versionado e independente dos detalhes internos do Vue Flow. A validação poderá utilizar JSON Schema, complementado pelas mesmas regras de negócio e integridade usadas no editor/backend.

### 5.1 Envelope lógico

O envelope abaixo registra a **intenção contratual já alinhada**. É **ilustrativo**, não uma amostra completa importável. A representação definitiva dos dados dos blocos deve ser derivada do catálogo real antes da implementação:

```json
{
  "format": "chait-flow",
  "schema_version": "1.0",
  "name": "Exemplo de fluxo",
  "nodes": [],
  "edges": []
}
```

- `format`: identificador inequívoco do formato CHAIT.
- `schema_version`: versão do **contrato de transporte**, distinta da versão de um rascunho/publicação ou do esquema interno do grafo.
- `name`: nome informativo para compartilhamento; **não altera o nome do fluxo aberto na importação**.
- `nodes`: blocos e configurações reconhecidos pelo catálogo da aplicação.
- `edges`: ligações explícitas, identificando blocos e saídas estáveis.

**Implementação:** os nomes detalhados das propriedades de cada bloco, os valores de `type`, os identificadores das saídas (`handle`) e a declaração de dependências precisam ser especificados **a partir do código real**, não do exemplo conceitual de conversas anteriores. O desenvolvedor relatou um grafo interno atualmente na versão 2; essa versão **não é automaticamente** `schema_version` do JSON compartilhável.

### 5.2 Fonte única de verdade

Segundo a análise apresentada pelo desenvolvedor, o projeto já dispõe de:

- `app/Services/Flows/FlowCapabilities.php` — catálogo de capacidades, tipos, configurações e saídas.
- `app/Services/Flows/FlowGraphValidator.php` — validação do grafo.
- `docs/modulos/assistente-ia-fluxos.md` — contexto do assistente e da edição compartilhada.

Esses caminhos são **informações fornecidas pelo desenvolvedor**, não resultado de auditoria do repositório nesta documentação.

**Diretriz:** o conversor de importação, o exportador, as regras de publicação e o guia para IA devem derivar ou validar suas informações contra o mesmo catálogo/regras já empregados pelo CHAIT. É permitido transformar entre formato portátil e armazenamento interno; **não** é permitido manter uma definição manual paralela de comportamento dos blocos.

### 5.3 Blocos e conexões

- Cada bloco deve possuir identificação portátil estável **dentro do arquivo**, suficiente para reconstruir as conexões.
- As conexões identificam uma origem, uma saída válida e um destino; a representação exata seguirá o catálogo.
- Um fluxo deve respeitar as restrições estruturais do editor, inclusive o bloco inicial único.
- **Ciclos são proibidos**. Não aceitar nem corrigir automaticamente ciclos importados.
- **Saída sem conexão encerra naturalmente aquele ramo**; não exigir um bloco terminal apenas para finalizar.
- Não criar, remover, rearranjar ou reconectar caminhos para "corrigir" uma lógica importada.
- O posicionamento visual é opcional no arquivo. Quando ausente ou inutilizável, o CHAIT calcula posições legíveis; essa organização **não altera ordem de execução nem conexões**. Se posições válidas forem incluídas, preservá-las quando possível.

## 6. Recursos e referências entre workspaces

### 6.1 Etiquetas e campos personalizados — criação automática aprovada

O JSON poderá declarar dependências de etiquetas e campos personalizados com referências **portáteis**, nomes e, para campos, o tipo necessário. A implementação deve relacionar essas referências com IDs reais do workspace **de destino**.

Comportamento aprovado:

1. Antes de confirmar, o servidor verifica a existência de recursos compatíveis no workspace.
2. Recursos equivalentes e compatíveis existentes são reutilizados, sem duplicação.
3. Recursos ausentes são **criados automaticamente quando o usuário confirma a importação**.
4. Conflitos de nome, tipo ou identidade **não são resolvidos por suposição**. Informar ao usuário o conflito; exigir correção/intervenção, sem sobrescrever recurso existente.
5. Criar recursos e substituir o rascunho como operação consistente: **uma falha não pode deixar etiquetas/campos órfãos criados apenas por uma importação malsucedida**.
6. Aplicar permissões e `workspace_id` no backend; não confiar em identificadores recebidos do JSON como autorização.

**Nota técnica:** a definição do que é um campo compatível (tipos disponíveis, normalização de nome, obrigatoriedade, eventual unicidade) deve seguir as regras reais do catálogo/cadastro. Não criar novos tipos de campo apenas porque um JSON os solicita.

### 6.2 Mídias — sem transporte de arquivos

- Imagem, vídeo, áudio e documento **não** são embutidos nem acompanhados por arquivos na exportação.
- Exportar apenas **tipo, posição na sequência e descrição útil** do item de mídia, sem ID interno, URL privada ou temporária reutilizável do workspace de origem.
- Após importar, cada item de mídia permanece no mesmo ponto da sequência, como **arquivo pendente de inserção**.
- O usuário anexa o arquivo pelo editor quando quiser.
- **Salvar rascunho é permitido; publicar com mídia obrigatória pendente é proibido.**

### 6.3 Atendentes e referências a outros fluxos

- Operações que apontavam para um **atendente específico** são importadas **sem atendente associado**.
- Blocos **Ir para Fluxo** são importados **sem fluxo de destino associado**.
- O usuário seleciona posteriormente, no próprio editor, o membro ou fluxo desejado.
- É permitido manter como texto informativo o nome original, quando disponível, **sem associação automática**.
- A publicação fica bloqueada **apenas quando uma referência exigida pelo tipo de operação permanecer vazia**. Por exemplo, abrir atendimento humano em fila não atribuída, se já for um modo válido desse bloco, não deve ser tratado como erro por não haver atendente.

### 6.4 Credenciais, identificadores e outros dados excluídos

Não transportar: chaves da OpenAI ou de provedores, tokens, segredos, credenciais, contatos, valores individuais de leads, histórico de conversas, histórico de execução, gatilhos ativos da conexão nem IDs internos de workspace/usuário/arquivo passíveis de uso cruzado.

**Risco aceito:** textos de mensagens e prompts podem conter informações confidenciais digitadas pelo próprio usuário. Não haverá inspeção automática desses textos no MVP; haverá somente o aviso de exportação da seção 3.2.

## 7. Particularidade dos blocos de IA

Há uma distinção deliberada entre **transportar um bloco existente** e **ensinar uma IA externa a gerá-lo**:

- O bloco de IA **continua compatível com importação e exportação**; não deve ser descartado.
- Na exportação, preservar seus **prompts, instruções e configurações** existentes, exceto segredos/credenciais.
- Na importação, preservar os **campos textuais preenchíveis**, especialmente prompts e instruções. **Opções selecionáveis**, como modelo de IA, assumem o **padrão atual do bloco**; o usuário pode editá-las depois.
- O **guia Markdown para IA externa não documentará a criação do bloco de IA**. Isso não torna um bloco existente desconhecido para o importador.

**Consequência intencional:** ao contrário dos blocos comuns, uma reimportação de bloco de IA pode **não conservar todas as escolhas selecionáveis**. Essa exceção deve ser observada nos testes e não pode ser confundida com perda silenciosa de prompts.

## 8. Validação: importação versus publicação

### 8.1 Importação — requisitos mínimos

**Rejeitar o arquivo integralmente** se houver, entre outros impedimentos estruturais:

- JSON malformado ou formato/versão incompatível.
- Tipo de bloco desconhecido.
- Identificadores ausentes/duplicados ou conexões apontando para blocos ou saídas inexistentes.
- Ciclos, conexões estruturalmente proibidas ou impossibilidade de reconstrução segura do grafo.
- Configuração desconhecida cuja interpretação exigiria descartar ou inventar uma decisão relevante da automação.
- Conflito de recurso que não possa ser resolvido de forma segura segundo a seção 6.1.

Nunca ignorar um bloco ou excluir uma ligação silenciosamente. Erros devem localizar o item problemático sempre que possível.

### 8.2 Rascunho — pode ficar incompleto

**Permitir importar e salvar como rascunho** um grafo estruturalmente reconhecido que tenha, por exemplo:

- Imagens/áudios/vídeos/documentos ainda sem arquivo.
- Atendimento com responsável obrigatório ainda não escolhido.
- Bloco **Ir para Fluxo** ainda sem destino.
- Outros valores obrigatórios para publicação ainda não preenchidos, desde que o bloco possa ser representado corretamente como pendente.

O sistema deve deixar cada pendência visível no editor.

**Distinção essencial:** "incompleto" não equivale a "JSON estruturalmente inválido". Um rascunho pode aguardar configurações; a importação não pode aceitar um grafo que exigiria adivinhar conexões ou perder blocos.

### 8.3 Publicação — validação completa

Na publicação, executar a **validação normal do CHAIT**, incluindo todos os dados obrigatórios, mídias pendentes, referências necessárias e demais regras atuais do catálogo/executor. Uma saída válida sem conexão **não** é erro: encerra o ramo.

Importar ou salvar **nunca publica automaticamente**. Se já há versão publicada, ela permanece válida e ativa enquanto o rascunho é alterado.

### 8.4 Erros e copiar para outra IA

Em erro, mostrar mensagens claras com identificador/local do bloco, campo ou conexão, e botão **Copiar erros**. O texto copiado deve ser útil para o usuário colar na IA externa e solicitar correção do JSON.

A importação **não usa IA**, não exige chave de OpenAI e não consome tokens por si mesma.

## 9. Versionamento e compatibilidade

- Todo arquivo possui a **versão do formato de transporte**.
- O botão de download fornece sempre o **guia atualizado** correspondente ao padrão suportado pelo CHAIT atual.
- Não implementar **migração/conversão automática de formatos antigos incompatíveis** na primeira entrega.
- Um arquivo antigo ainda compatível pode ser aceito; um incompatível será rejeitado com explicação.
- Caso o fluxo original ainda exista no CHAIT atualizado, o usuário poderá **exportá-lo novamente** para obter um arquivo atual.
- Se possuir **somente o JSON antigo incompatível**, sem o fluxo original, a recuperação não estará garantida. Esta limitação foi aceita em favor de menor complexidade.
- **Não** alterar `schema_version` a cada atualização visual ou de aplicação: ela representa mudanças do **contrato JSON**, não toda atualização do CHAIT.

## 10. Guia Markdown para IA externa

Disponibilizar **Baixar guia para IA** dentro do editor de fluxos. O arquivo `.md` será baixado e anexado pelo usuário à ferramenta de IA de sua preferência.

O guia destinado ao usuário deve:

- Explicar, em linguagem direta, como pedir um fluxo à IA e importá-lo no CHAIT.
- Incluir o formato JSON suportado, seus limites e exemplos **realmente válidos**.
- Documentar os tipos de blocos **autorizados para criação por IA externa**, incluindo significado das saídas e regras de execução.
- Explicar como declarar etiquetas e campos para criação/associação e como representar arquivos pendentes.
- Alertar sobre a necessidade de revisar o resultado antes de publicar.
- **Não incluir instruções para criar blocos de IA**.
- **Não abordar coordenadas, handles internos, migrações e outros detalhes de implementação desnecessários ao usuário**.

**Diretriz de manutenção:** gerar/atualizar o conteúdo a partir do mesmo catálogo de capacidades, complementando-o com explicações humanas e exemplos testados. É desejável que os exemplos do guia sejam submetidos à mesma validação de importação no processo de testes.

A especificação técnica poderá detalhar posições opcionais e a transformação de IDs, mas **essa complexidade fica fora do texto de uso cotidiano**.

## 11. Concorrência, integridade e segurança

### 11.1 Proteção contra edições simultâneas

O editor e o assistente interno podem editar o mesmo rascunho. Antes de substituir, o backend deve comparar a **revisão esperada** com a revisão persistida.

- Se houver conflito, **bloquear** a importação, sem substituir o rascunho nem criar campos/etiquetas.
- Mensagem sugerida: **"Este fluxo foi modificado em outra sessão. Atualize o editor antes de importar."**
- Evitar condição de corrida entre validação, confirmação e persistência: revalidar estado/revisão no momento de confirmar.

### 11.2 Operação consistente

A etapa confirmada deve garantir, como uma unidade de alteração, a verificação de revisão, associação/criação de recursos, gravação do novo rascunho e atualização de sua revisão. Usar transação/controles equivalentes no banco conforme a arquitetura real.

Não executar ações de automação ao **interpretar** o JSON. Trata-se de dados, não código executável. Respeitar políticas de autorização para editar/importar/exportar/publicar e impor limites de tamanho, profundidade e quantidade de blocos para evitar abuso; definir limites concretos de acordo com o desempenho testado.

### 11.3 Isolamento

O sistema deve derivar o workspace **do fluxo autenticado e autorizado**, nunca confiar no workspace ou em IDs internos presentes no arquivo. A criação de etiquetas/campos e a associação de dependências devem ocorrer exclusivamente nesse workspace.

## 12. Critérios de aceite

| ID | Cenário | Resultado esperado |
|---|---|---|
| IE-01 | Importar dentro de fluxo aberto | Continua no mesmo editor; mantém ID e nome do fluxo |
| IE-02 | Confirmar importação válida | Substitui e salva o rascunho integralmente |
| IE-03 | Cancelar confirmação | Nada é substituído ou criado |
| IE-04 | Importar JSON malformado | Rejeita, explica e preserva rascunho |
| IE-05 | Importar bloco desconhecido | Rejeita arquivo inteiro, sem descartá-lo |
| IE-06 | Importar grafo com ciclo | Rejeita e identifica conexões problemáticas |
| IE-07 | Importar saída válida sem ligação | Aceita; publicação é possível se demais requisitos estiverem satisfeitos |
| IE-08 | Importar com mídia | Preserva sequência e tipo, deixa arquivo pendente; impede publicação até resolução |
| IE-09 | Importar etiqueta/campo ausente | Cria recurso no workspace somente após confirmação |
| IE-10 | Reimportar recurso equivalente existente | Reutiliza sem criar duplicata |
| IE-11 | Campo existente incompatível | Não sobrescreve; apresenta conflito para intervenção |
| IE-12 | Falha durante gravação | Não deixa rascunho alterado nem recursos criados pela importação malsucedida |
| IE-13 | Bloco com atendente específico | Importa bloco sem atendente selecionado, passível de edição |
| IE-14 | Bloco Ir para Fluxo | Importa sem destino selecionado, passível de edição |
| IE-15 | Importar sobre fluxo já publicado | Versão publicada segue intacta; só rascunho é substituído |
| IE-16 | Exportar rascunho incompleto | Gera arquivo de transporte, sem exigir publicação |
| IE-17 | Exportar versão publicada | Gera dados consistentes daquela versão, sem misturar com rascunho |
| IE-18 | Exportar texto/mídia | Mantém texto e posição de mídia, sem transportar bytes/URLs privadas |
| IE-19 | Exportar bloco IA e reimportá-lo | Preserva prompts; selecionáveis voltam ao padrão do bloco; não revela credenciais |
| IE-20 | Importar JSON sem posições | Organiza blocos de modo legível sem alterar conexões |
| IE-21 | Outro editor altera o rascunho | Importação com revisão antiga é bloqueada; sem efeitos parciais |
| IE-22 | Erro de importação | Exibe erros específicos e permite copiá-los |
| IE-23 | Arquivo de versão incompatível | Rejeita com mensagem explicativa; sem conversão automática |
| IE-24 | Baixar guia Markdown | Entrega guia atual, com exemplos compatíveis e sem instruções de criação de bloco IA |
| IE-25 | Workspace distinto | Não usa IDs/recursos de outro workspace nem transfere credenciais |
| IE-26 | JSON contém nome diferente | Mantém nome do fluxo aberto |
| IE-27 | Importar arquivo válido | Não dispara automação, não altera gatilhos e não publica automaticamente |

## 13. Orientações ao desenvolvedor antes de implementar

Este documento registra **o comportamento do produto**. Para detalhar o JSON público, o desenvolvedor deverá inspecionar os tipos, os campos, os valores padrão e os handles **que realmente existem** no repositório e entregar:

1. **Contrato JSON/JSON Schema** compatível com o catálogo e validadores internos.
2. Transformações determinísticas de **formato portátil ↔ grafo interno**, incluindo dependências, pendências e posições.
3. Adequação do caminho de **salvar rascunho** para aceitar blocos incompletos, mantendo a **publicação** estrita.
4. Implementação da importação transacional com controle de revisão e autorização de workspace.
5. Guia `.md` para a IA externa, com exemplos completos validados automaticamente.

**Não presumir** que os nomes de propriedades dos exemplos conceituais das conversas correspondem ao contrato de produção. Não duplicar o catálogo de blocos em um subsistema paralelo de importação.

## 14. Registro resumido das decisões aprovadas

| Tema | Decisão |
|---|---|
| Formato | JSON CHAIT único, próprio e versionado |
| Entrada | Somente no editor do fluxo individual, colagem ou upload |
| Efeito da entrada | Substitui rascunho, com confirmação, salva automaticamente e permanece no editor |
| Versão publicada | Não alterada pela importação |
| Recuperação específica | Sem backup/desfazer de importação |
| Prévia | Sem prévia nem resumo; apenas aviso de substituição |
| Etiquetas e campos | Reutilizar compatíveis e criar ausentes automaticamente após confirmação |
| Mídias | Transportar somente descrição/tipo/ordem; arquivos inseridos depois |
| Atendentes e outros fluxos | Importar blocos sem destino selecionado |
| Rascunho | Pode permanecer incompleto e ser exportado |
| Publicação | Bloqueada por pendências obrigatórias; saídas livres encerram o caminho |
| Falhas de formato | Blocos desconhecidos, ciclos e conexões irrecuperáveis impedem importação |
| Erros | Mensagem clara com botão **Copiar erros**; sem chamada de IA |
| Posições | Opcionais; auto-organização quando necessário; não explicar coordenadas ao usuário |
| Exportação | Somente no editor; um fluxo por JSON; rascunho salvo ou versão publicada |
| Guia para IA externa | Baixar arquivo Markdown atualizado |
| Bloco IA | Transportável, mas excluído das instruções de criação; textos mantidos, opções selecionáveis no padrão ao importar |
| Configurações dos demais blocos | Preservar opções e valores válidos do arquivo |
| Compatibilidade | Sem migração automática de formatos antigos incompatíveis |
| Fonte de verdade | Catálogo e regras reais do CHAIT, sem duplicar especificação funcional |
| Concorrência | Verificação de revisão; conflito impede substituição e efeitos parciais |
| Privacidade | Credenciais excluídas; aviso de possível conteúdo sensível antes de exportar |

---

**Observação de alinhamento com documentos anteriores:** `docs/modulos/editor-de-fluxos.md` (versão de briefing disponível nesta conversa, datada de 12/10/2026) registrava importação/exportação fora do escopo inicial do editor. **Esta especificação substitui essa exclusão para o recurso de importação/exportação aqui definido.** O documento principal do editor e o registro de decisões do repositório deverão ser sincronizados quando esta especificação for incorporada ao projeto.

## Complementos aprovados em 15/10/2026

1. Quem pode gerenciar fluxos pode reutilizar/criar etiquetas e campos pela importação, sem permissão de IA nem chave. A tela administrativa mantém sua regra atual. Os endpoints de criação dentro do editor já usam essa permissão.
2. Importação bloqueada enquanto a IA interna estiver em fila ou trabalhando. Após parar/concluir, a confirmação substitui também a prévia compartilhada, invalida o plano anterior e exige novo plano para nova construção. O histórico permanece.
3. Operações Adicionar à sequência são preservadas sem destino; selecionar uma sequência válida continua obrigatório antes de publicar quando a operação for alcançável.
4. Recursos autorizados no bloco IA seguem o mesmo tratamento: etiquetas/campos associados ou criados, mídias pendentes e instruções preservadas. Prompts e limites numéricos permanecem; modelo, ações de expiração/limite e opção de sobrescrever campos voltam aos padrões atuais.

O contrato implementado e os detalhes de operação ficam no documento vigente.
