# Editor Visual de Fluxos e Automações

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Especificação funcional e técnica — revisão documental de 16/10/2026**  
> **Projeto:** SaaS Laravel de atendimento e automações multicanal  
> **Data:** 12/10/2026  
> **Estado:** editor, publicação, runtime, oito tipos de nó, pastas, importação/exportação e Assistente IA implementados localmente; homologações reais parciais nos módulos relacionados não certificam todos os cenários  
> **Destino no repositório:** `docs/modulos/editor-de-fluxos.md`

## 1. Objetivo

Permitir que um administrador monte automações de atendimento de maneira visual, arrastando blocos no canvas, conectando-os com setas e configurando as ações sem programar.

O foco da versão inicial é **automação de conversas recebidas pelo WhatsApp**, aproveitando os contatos, as etiquetas, os campos personalizados e o módulo de atendimento humano já existentes ou previstos no SaaS.

Esta especificação **não substitui** a documentação do módulo de multiatendimento (`docs/modulos/chat-multiatendimento.md`): os dois módulos devem compartilhar os mesmos conceitos de workspace, contato, conversa, conexão WhatsApp, membro da equipe e permissões.

**Direção aprovada em 12/10/2026:** UAZAPI como provedor inicial, um número de WhatsApp por workspace e frontend Vue 3 + Inertia.js com Vue Flow neste editor. O chat e a base visual já estão implementados; esta etapa acrescenta configuração e execução real.

### 1.1. Princípios aprovados

- Interface simples para usuários não técnicos.
- Poucos tipos de bloco, cada um com responsabilidade bem definida.
- Canvas limpo, com conexões explícitas e sem ramificações ocultas.
- Reutilização de fluxos e de campos personalizados do contato.
- Admin controla as prioridades e regras de palavras-chave.
- Execução real em filas no backend; o editor configura e publica versões, mas não executa automações pelo navegador.
- Entrega incremental, sem incluir funções futuras na primeira versão.

## 2. Escopo funcional desta entrega

### 2.1. Aprovado

1. Lista de fluxos com criação, busca, renomeação e organização em pastas aninhadas.
2. Editor visual com bloco **Start** inicial, canvas livre e painel de edição à esquerda.
3. Blocos **Conteúdo**, **Aguardar Resposta**, **Operações**, **Ir para Fluxo**, **Condição**, **IA** e **Menu**.
4. Conectores visíveis: fixos nos blocos existentes e dinâmicos por opção no Menu.
5. Botão `+` no canto superior direito para adicionar bloco.
6. Botão `+` próximo à saída dos blocos para criar automaticamente o próximo bloco **já conectado**.
7. Fluxos comuns podem ser selecionados como **Boas-vindas**, **Resposta padrão** ou destino de **Palavras-chave**.
8. Configuração dos gatilhos no contexto da conexão de WhatsApp.
9. Palavras-chave com operadores `É igual a`, `Começa com` ou `Contém`, prioridade pela ordem visual e opção de interromper fluxo ativo.
10. Fluxo de boas-vindas executado apenas no primeiro contato de cada lead com cada conexão WhatsApp, se uma palavra-chave não for acionada primeiro.
11. Fluxo de resposta padrão quando não há palavra-chave aplicável, não é primeiro contato e não há fluxo ativo.
12. Automações pausadas para atendimento humano ou pausa manual não aceitam novos gatilhos.
13. Publicação versionada, gatilhos por conexão, campos e etiquetas do contato e execução assíncrona no Laravel.

### 2.2. Fora do escopo desta versão

- Outros canais além do WhatsApp para disparo automático.
- Gatilhos por evento de compra, webhook externo, horário, campanha, API pública ou agendamento.
- Motor de condições complexo com grupos aninhados `E/OU`.
- Variáveis com validação avançada (CPF, e-mail, datas etc.).
- Reconhecimento semântico de intenção ou correspondência aproximada de palavras-chave. O bloco IA é uma evolução separada, descrita em [Bloco IA](bloco-ia.md).
- Versões diferentes do fluxo em testes A/B ou analytics avançado.
- Simulador, marketplace de modelos e canais além do WhatsApp. Importação/exportação foi incorporada em 15/10/2026 conforme [contrato próprio](importacao-exportacao-fluxos.md).

## 3. Biblioteca de fluxos

### 3.1. Organização

A tela anterior ao editor permite nesta entrega:

- Criar, renomear, pesquisar, abrir e mover fluxos entre pastas do workspace. Pastas podem conter subpastas em qualquer profundidade. Excluir uma pasta remove suas subpastas e devolve todos os fluxos descendentes à **Área principal**.
- Cada pasta e fluxo tem menu de três pontos com **Renomear**, **Mover para** e **Excluir**. Pastas exibem ícone e um caminho de navegação até a raiz.
- Excluir fluxo só é permitido para rascunho nunca publicado, sem execução nem referências em gatilhos ou outros fluxos.
- Nomear o fluxo durante a criação e renomeá-lo posteriormente.
- Encontrar fluxos pelo nome.
- Diferenciar fluxos em **rascunho** dos **publicados**.

### 3.2. Natureza dos fluxos

Todos os fluxos são da mesma espécie. **Boas-vindas** e **Resposta padrão** não são tipos especiais de fluxo: são apenas fluxos normais selecionados em configurações.

Um mesmo fluxo pode ser:

- Marcado como boas-vindas de determinada conexão;
- Associado a uma ou mais palavras-chave;
- Escolhido como resposta padrão;
- Destino de um bloco `Ir para Fluxo`.

A interface não deve impedir esses usos simultâneos.

## 4. Editor visual (frontend)

### 4.1. Tecnologias acordadas

A aplicação usa Vue 3 + Inertia.js; o editor e o runtime estão implementados nessa base.

| Camada              | Tecnologia                     | Responsabilidade                                         |
| ------------------- | ------------------------------ | -------------------------------------------------------- |
| Editor              | Vue 3 + **Vue Flow**           | Nós, conectores, setas, zoom, pan e drag-and-drop        |
| Aplicação web       | **Inertia.js**                 | Integração frontend/backend                              |
| Interface e estilos | Padrões já adotados no projeto | Componentes, estados e consistência visual               |
| Backend             | **Laravel**                    | Validação, publicação, gatilhos e execução de automações |
| Filas               | **Redis + Horizon**            | Execução assíncrona e espera agendada                    |
| Persistência        | PostgreSQL                     | Fluxos, versões, regras e estado de execução             |

> O ambiente atual de desenvolvimento e testes usa PostgreSQL; os testes são isolados da aplicação conforme o [guia de desenvolvimento](../desenvolvimento/postgresql.md).

### 4.2. Composição da tela

- **Barra superior discreta:** nome do fluxo, estado do rascunho e ações `Salvar`, `Publicar` e `Desativar`.
- **Canvas central:** área clara e livre, com zoom, deslocamento e arraste de blocos.
- **Painel de edição à esquerda:** abre ao selecionar ou criar bloco; fecha quando não há bloco selecionado.
- **Botão global `+`:** canto superior direito, abre menu com os tipos de bloco.
- **Botão `+` nas saídas:** adiciona novo bloco no canvas, já ligado à saída que originou a ação.
- **Blocos compactos:** mostrar nome e resumo do conteúdo/configuração, sem expandir o formulário inteiro sobre o canvas.

### 4.3. Comportamentos obrigatórios

- Um fluxo novo inicia visualmente apenas com o bloco `Start`.
- Cada fluxo possui **um único** `Start`; ele não pode ser duplicado ou excluído.
- Os conectores permanecem visualmente associados a pontos fixos do bloco.
- Setas têm origem e destino identificáveis; saídas com significado diferente usam rótulos.
- Não permitir conexões estruturalmente inválidas, por exemplo, ligando uma saída a outra saída.
- O editor preserva as posições dos nós ao salvar/reabrir.
- A edição de um bloco não deve alterar silenciosamente as ligações de outros blocos.
- Ao remover uma saída ou bloco com ligações, avisar sobre as conexões que serão afetadas.
- Mudanças não publicadas não devem alterar imediatamente um fluxo que está rodando em produção.

## 5. Contrato dos tipos de bloco

| Bloco               | Entradas | Saídas                                   | Função                                      |
| ------------------- | -------: | ---------------------------------------- | ------------------------------------------- |
| `Start`             |        0 | `Próximo passo` (1)                      | Início de cada fluxo                        |
| `Conteúdo`          |        1 | `Próximo passo` (1)                      | Enviar mensagens/mídia com atrasos          |
| `Aguardar Resposta` |        1 | `Respondeu`, `Não respondeu` (2)         | Aguardar e armazenar resposta               |
| `Operações`         |        1 | `Próximo passo` (1)                      | Executar operações em sequência             |
| `Ir para Fluxo`     |        1 | **0**                                    | Encerrar o fluxo atual e iniciar outro      |
| `Condição`          |        1 | `Sim`, `Não` (2)                         | Testar condições                            |
| `IA`                |        1 | `Continuar`; atendimento humano terminal | Conversar com o contato conforme o bloco IA |
| `Menu`              |        1 | Uma por opção + `Continuar`              | Apresentar escolhas e aguardar seleção      |

**Convenção técnica sugerida:** usar identificadores estáveis (`type`, `node_id`, `handle_id`) independentes do nome que aparece na tela. O frontend e o executor devem consumir o mesmo contrato validado pelo backend.

### 5.1. Bloco Start

- Criado automaticamente com o fluxo.
- Apenas indica o ponto de início da execução.
- Possui uma única saída para o próximo passo.
- Gatilhos (palavra-chave, boas-vindas e resposta padrão) são configurados **fora** do canvas.

### 5.2. Bloco Conteúdo

Permite combinar, **na mesma instância**, uma sequência ordenada de itens:

- Texto com editor simples: **negrito**, _itálico_ e ~~tachado~~;
- Imagem;
- Vídeo;
- Áudio;
- Arquivo;
- Atraso entre itens;
- Um elemento **Botão**, com mensagem própria e uma ação URL, Resposta ou Copiar. [Contrato e execução](bloco-conteudo-elemento-botao.md).

Exemplo:

1. Texto: `Olá! Vou te enviar nosso catálogo.`
2. Atraso: `3 segundos`.
3. Imagem: catálogo.
4. Atraso: `2 segundos`.
5. Texto: `Qual produto te interessou?`

Cada item deve ser executado na ordem em que aparece no painel de edição.

Cada atraso fica entre **3 e 10 segundos**, com padrão de 3. O atraso espera antes do próximo item e, se for o último item do bloco, antes do próximo bloco.

Imagem (JPEG, PNG, WebP ou GIF), vídeo (MP4), áudio (MP3 ou OGG) e documento (PDF, DOC/DOCX, XLS/XLSX ou texto) são enviados por `/send/media`. Arquivos ficam no disco privado e o grafo guarda somente seu ID. [Contrato oficial UAZAPI](https://docs.uazapi.com/reference/sendMedia.md).

Saída única: `Próximo passo`.

**Atenção:** `Aguardar Resposta` **não** fica dentro do bloco Conteúdo na solução final; é um bloco separado.

### 5.3. Bloco Aguardar Resposta

Campos de configuração:

- **Campo personalizado de destino:** selecionar ou criar campo vinculado ao lead.
- **Tempo máximo de espera:** valor configurável (ex.: 30 minutos, 2 horas, 24 horas).
- **Saídas:** `Respondeu` e `Não respondeu`.

Fluxo esperado:

1. A automação chega ao bloco e registra uma espera ativa para aquele contato/conexão.
2. Se uma mensagem válida para essa espera chegar antes do vencimento, armazena o conteúdo no campo do **respectivo lead** e segue pelo caminho `Respondeu`.
3. Se o prazo terminar sem resposta, segue por `Não respondeu` **sem preencher o campo com resposta inexistente**.
4. Uma resposta recebida **depois** de consumido ou expirado o prazo não reabre automaticamente a etapa encerrada.

**MVP de variáveis:** começar com campos personalizados do tipo **texto**. Outros tipos (número, data, data/hora, e-mail, CPF etc.) ficam para uma evolução posterior. A modelagem deve permitir expandir tipos futuramente.

**Distinção importante:** o campo é definido no workspace, mas seu **valor pertence ao lead**. Valores não podem ser compartilhados entre leads.

O motor deve garantir que `Respondeu` e `Não respondeu` sejam **mutuamente exclusivos**: apenas um deles pode vencer, inclusive se resposta e timeout ocorrerem quase simultaneamente.

### 5.4. Bloco Operações

Nome aprovado: **Operações**.

Permitir uma lista sequencial de operações dentro do mesmo bloco:

1. Adicionar etiqueta ao lead.
2. Remover etiqueta do lead.
3. Atualizar o valor de um campo personalizado do lead.
4. Abrir atendimento humano, escolhendo um atendente ou deixando a conversa **não atribuída**, disponível para a fila de atendimento. **Regra confirmada:** somente um gatilho/ação definido para abertura humana inicia esse atendimento; uma mensagem recebida não o inicia por si só.
5. Notificar membro da equipe via mensagem WhatsApp enviada pela conexão de atendimento.
6. Pausar automações para que o lead não receba respostas automatizadas durante a pausa.

Saída única: `Próximo passo`.

**Reutilizar regras do multiatendimento:** visibilidade, permissão para atribuir/transferir, seleção de membros, status e fila de conversas; não criar um sistema paralelo.

Para a notificação:

- Escolher membro autorizado da equipe.
- Usar o número de WhatsApp cadastrado para o membro e a conexão configurada.
- Tratar envio como operação sujeita às regras e limitações do provedor (não presumir que todo número aceita mensagens a qualquer momento).
- Mensagens enviadas pelo próprio sistema **não** devem ser interpretadas como mensagens recebidas para disparar palavras-chave.

**Regra mínima de segurança:** nenhuma execução, inclusive aquela com atraso ou timeout já agendado, pode enviar resposta automática enquanto a pausa estiver vigente. Jobs precisam conferir o estado da pausa antes de qualquer envio.

**Regra implementada:** abrir ou assumir atendimento humano cancela a execução e libera novos gatilhos apenas quando o atendimento é finalizado. Pausa manual com prazo (1 minuto a 7 dias) libera novos gatilhos ao vencer; pausa indefinida exige que alguém autorizado na conversa clique em **Retomar automações**. Nenhuma dessas pausas retoma uma execução antiga. Abrir atendimento ou pausar encerra a execução e deve ser a última operação do bloco.

### 5.5. Bloco Ir para Fluxo

- Apenas uma entrada; **nenhuma saída**.
- No painel esquerdo, permitir pesquisar e selecionar um fluxo de destino **publicado, válido e pertencente ao workspace**.
- Quando alcançado, **encerrar definitivamente** a execução atual.
- Iniciar o fluxo de destino a partir de seu próprio `Start`, para o mesmo lead/conexão, sem retorno à origem.
- Preservar dados e campos personalizados do lead.
- Proibir referência a si próprio e ciclos entre fluxos publicados (`A → B → A`).
- Validar ciclos **na publicação** e aplicar limite de segurança também **em tempo de execução**, para proteger contra alterações concorrentes, configurações antigas ou falhas.

Não usar a pasta ou o nome do fluxo para identificar o destino; usar ID estável.

### 5.6. Bloco Condição

Consulte a [especificação funcional e técnica do Bloco Condição](bloco-condicao.md).

O bloco consulta etiquetas e campos de texto do contato, combinando verificações com **Todas (E)** ou **Pelo menos uma (OU)**. Etiquetas oferecem **Possui/Não possui**; campos oferecem **É igual a/Contém**. Campos elegíveis: Nome, E-mail, CPF e personalizados de texto do workspace. Comparações ignoram acentos, maiúsculas e espaços nas extremidades; campos vazios não correspondem.

Configuração em cartões no painel esquerdo, com resumo no canvas e saídas fixas **Sim/Não**. Rascunhos incompletos são permitidos; publicar exige configuração válida nos blocos alcançáveis. O assistente IA do editor cria/edita condições sem consultar valores dos contatos.

Etiquetas e campos têm exclusão administrativa com confirmação; referências em versões publicadas, execuções ativas, sequências preservadas e webhooks ativos impedem a exclusão no backend. O contrato novo preserva grafos antigos sem reescrever versões publicadas.

### 6.1. Primeiro contato e boas-vindas

- `Primeiro contato` significa a **primeira mensagem nova e elegível recebida após a ativação** daquele lead naquela conexão WhatsApp; não vem de importação ou histórico e não equivale a reabrir a conversa ou mandar mensagem depois de semanas.
- Boas-vindas deve disparar **no máximo uma vez por lead por conexão**.
- A primeira mensagem é classificada **antes** de escolher o fluxo: se houver palavra-chave elegível, ela tem prioridade sobre boas-vindas.
- Se a primeira mensagem disparar uma palavra-chave, **não enviar boas-vindas depois** como compensação; o primeiro contato já ocorreu.
- O fluxo de boas-vindas continua sendo um fluxo reutilizável, podendo ser executado mais tarde por palavra-chave ou `Ir para Fluxo`, sem que isso signifique um segundo disparo do **gatilho automático de primeiro contato**.
- Evitar resetar acidentalmente o histórico de primeiro contato ao encerrar, transferir ou reabrir a conversa.

### 6.2. Resposta padrão

Quando uma **mensagem recebida** não disparar palavra-chave:

- Se for o primeiro contato e existir boas-vindas configurado, inicia boas-vindas; se não houver boas-vindas, pode iniciar o fluxo padrão.
- Se o contato **já interagiu anteriormente**, **não houver fluxo ativo** e as automações **não estiverem pausadas**, inicia o fluxo de resposta padrão, se configurado.
- Se já houver uma automação ativa, **não** iniciar resposta padrão; respeitar o estado atual.
- Se automações estiverem pausadas para atendimento humano, **não** iniciar resposta padrão.

**Nota:** resposta padrão não é uma resposta de texto fixa; é um **fluxo normal selecionado** para esse papel.

### 6.3. Palavras-chave: formulário e lista

Cada regra deve conter, no mínimo:

| Campo                          | Comportamento                                     |
| ------------------------------ | ------------------------------------------------- |
| Palavra-chave / expressão      | Texto informado pelo administrador                |
| Tipo de correspondência        | `É igual a`, `Começa com`, `Contém`               |
| Fluxo de destino               | Selecionar fluxo publicado do workspace           |
| Interromper fluxo em andamento | `Ligado` ou `Desligado`; **desligado por padrão** |
| Posição de prioridade          | Determinada pela ordem na lista                   |

A tela de **Palavras-chave** precisa permitir **reordenar as regras visualmente** (por exemplo, arrastar e soltar). Salvar essa ordem de forma persistente.

Mostrar aviso curto na interface:

> **As palavras-chave são verificadas de cima para baixo. A primeira regra aplicável tem prioridade.**

#### Interpretação dos operadores

- `É igual a`: mensagem inteira igual à expressão configurada.
- `Começa com`: mensagem começa pela expressão.
- `Contém`: a expressão aparece em qualquer posição da mensagem.
- Comparação sem diferenciar letras maiúsculas/minúsculas ou acentos.
- Para tornar os resultados previsíveis, **recomendação técnica**: remover espaços nas pontas e normalizar Unicode antes da comparação; não usar IA nem correspondência semântica no MVP.

Exemplos com o termo `curso`:

| Mensagem recebida | É igual a | Começa com | Contém |
| ----------------- | :-------: | :--------: | :----: |
| `Curso`           |    Sim    |    Sim     |  Sim   |
| `curso de inglês` |    Não    |    Sim     |  Sim   |
| `Quero um curso`  |    Não    |    Não     |  Sim   |
| `cursos`          |    Não    |    Sim     |  Sim   |

**Importante:** `Contém` e `Começa com` fazem **correspondência textual**, não identificação de palavra inteira; o exemplo `cursos` acima é proposital.

#### Ordem e elegibilidade

A ordem na lista é a **única prioridade entre regras elegíveis**: não implementar heurísticas de "termo mais longo", "mais específico" ou "operador exato ganha".

- Quando **não há fluxo ativo**, são elegíveis as regras que correspondem à mensagem.
- Quando **há fluxo ativo**, só são elegíveis as regras que correspondem **e** estão marcadas como `Interromper fluxo em andamento = Ligado`.
- As regras são avaliadas de cima para baixo; **apenas a primeira elegível** dispara. Uma mensagem nunca inicia dois fluxos.
- Regra que corresponde, mas não tem autorização para interromper, **não encerra** o fluxo em andamento. A varredura pode continuar até encontrar uma regra elegível.
- Quando as automações do lead estiverem **pausadas**, nenhuma palavra-chave dispara, independentemente da posição e da flag de interrupção.

Se uma regra interromper:

1. Finalizar a execução anterior como **interrompida**, cancelando seus aguardos/continuações pendentes.
2. Iniciar o fluxo escolhido **uma única vez**.
3. Nunca deixar jobs antigos retomarem a execução interrompida.

#### Mensagens recebidas durante uma espera por resposta

A chegada de uma mensagem quando existe `Aguardar Resposta` ativo deve respeitar a ordem:

1. Verificar pausa: se as automações estão pausadas, **não** acionar palavras-chave, capturar texto ou produzir mensagens automáticas. Pausas cancelam a execução e qualquer espera antiga.
2. Se não está pausado, procurar primeiro uma **palavra-chave elegível para interromper** o fluxo. Se encontrada, ela vence, interrompe a execução atual e a mensagem **não** é usada para preencher o campo da pergunta interrompida.
3. Se não houver palavra-chave interruptiva elegível, entregar a mensagem à espera pendente e seguir por `Respondeu`, se o prazo ainda estiver válido.
4. Se a espera já expirou, não reabri-la. Para mensagens sem execução ativa, aplicar a classificação normal de palavras-chave e resposta padrão.

Isso evita que a mesma mensagem simultaneamente dispare outro fluxo e seja salva como resposta do fluxo antigo.

### 6.4. Ordem geral de decisão para mensagem recebida

```text
Mensagem recebida da conexão WhatsApp
          |
          v
Registrar evento com idempotência + identificar workspace/lead/conexão
          |
          v
Automações deste lead estão pausadas?
    | Sim                       | Não
    v                           v
Não dispara fluxo       Há automação ativa?
                        | Sim                | Não
                        v                    v
                 Procurar primeira      Procurar primeira
                 palavra-chave          palavra-chave
                 correspondente         correspondente
                 com "interromper"      na ordem da lista
                 ligado
                 | Achou | Não          | Achou | Não
                 v       v              v       v
              Interrompe  Mantém       Inicia   É primeiro
              e inicia    execução     fluxo    contato?
              novo fluxo  atual*                 | Sim  | Não
                                               v      v
                                           Boas-     Resposta
                                           vindas    padrão
```

(\* Se a execução atual estiver aguardando resposta, apenas texto pode preencher o campo e seguir `Respondeu`; anexos e áudio deixam a espera continuar até o prazo.)

Os gatilhos só iniciam fluxos publicados e ativos. No primeiro contato, palavra-chave tem prioridade; sem palavra-chave, executa boas-vindas ou, se ela não estiver configurada, resposta padrão.

> **Regra de concorrência:** a checagem de fluxo ativo, pausa, ordem de palavras-chave e decisão de disparo precisam observar estado consistente do mesmo lead/conexão.

## 7. Estados e execução no Laravel

### 7.1. Separação de responsabilidades

- **Vue Flow**: apenas desenho, edição e serialização do grafo.
- **Laravel**: valida grafo, configurações e permissões; publica versões imutáveis; decide gatilhos; percorre nós; manipula lead/conversa; dispara mensagens.
- **Filas/Horizon**: realizam trabalho assíncrono, envios, atrasos e timeouts.
- **Banco**: fonte de verdade do estado, resultados, registros de primeiro contato, prioridades e execução.

O runtime não deve depender de uma aba do navegador aberta.

### 7.2. Modelo lógico sugerido (não é migration definitiva)

Entidades ou tabelas lógicas:

| Entidade                | Dados relevantes                                                                               |
| ----------------------- | ---------------------------------------------------------------------------------------------- |
| Pastas                  | `id`, `workspace_id`, `parent_id` opcional, `name`                                             |
| Fluxos                  | `id`, `workspace_id`, `folder_id` opcional, `name`, `status`, versão publicada                 |
| Versões de fluxo        | `flow_id`, `version`, `nodes`, `edges`, data de publicação                                     |
| Configuração de entrada | `workspace_id`, `connection_id`, `welcome_flow_id`, `default_flow_id`                          |
| Regras de palavra-chave | `workspace_id`, `connection_id`, `term`, `operator`, `flow_id`, `priority`, `interrupt_active` |
| Primeiro contato        | `workspace_id`, `connection_id`, `lead_id`, `first_inbound_at` ou registro equivalente         |
| Execuções               | `flow_id`, `flow_version`, `lead_id`, `connection_id`, `current_node`, `status`, timestamps    |
| Esperas de resposta     | `execution_id`, `node_id`, `field_id`, `expires_at`, `status`, mensagem consumida              |
| Pausa de automações     | Estado persistente do lead/conexão e motivo/origem da pausa                                    |
| Log de eventos          | IDs de mensagens/webhooks processados, transições de execução e erros                          |

**Não criar tabelas duplicadas** para lead, etiquetas, campos personalizados, membros ou conversas se elas já existirem no SaaS. Adaptar essas entidades à modelagem atual antes de escrever migrations.

### 7.3. Estados sugeridos

- **Fluxo (editor):** rascunho, versão publicada e estado ativo/inativo, mantendo versões publicadas imutáveis.
- **Execução:** `running`, `waiting_reply`, `completed`, `interrupted`, `failed`.
- **Pausa do lead:** estado próprio separado da execução; pausar um lead não deve apagar seus campos ou histórico.

Os nomes internos podem seguir as convenções do repositório, preservando a semântica.

### 7.4. Garantias de robustez indispensáveis

1. **Isolamento multiworkspace:** IDs de pastas, fluxos, contatos, membros, etiquetas e campos devem ser validados sempre pelo workspace da operação.
2. **Idempotência:** reenvio de webhook ou retry de fila não pode duplicar disparos, resposta capturada ou mensagens enviadas por erro de controle de estado.
3. **Concorrência:** duas mensagens simultâneas de um lead não podem iniciar dois fluxos conflitantes.
4. **Timeout atômico:** resposta e vencimento disputam a mesma espera, garantindo apenas uma saída.
5. **Jobs obsoletos:** antes de qualquer envio ou continuação, verificar se a execução ainda está ativa e se o passo esperado ainda é o atual.
6. **Versões:** execuções devem usar definição publicada consistente; edição de rascunho não altera nós de execução corrente.
7. **Limites de segurança:** impedir ciclos de `Ir para Fluxo`, reentrância infinita e avalanche de tarefas.
8. **Observabilidade:** registrar causa do disparo (`keyword`, `welcome`, `default`, `flow_jump`), regra aplicada, versão, bloco, erro e resultado.
9. **Falhas externas:** tratar indisponibilidade e restrições da API WhatsApp sem presumir entrega bem-sucedida.
10. **Segurança dos campos:** nunca permitir salvar resposta do contato A no campo do contato B, nem acessar recursos de outro workspace.
11. **Mídias:** respeitar armazenamento e autorização de acesso já utilizados pelo SaaS; não assumir URLs públicas para arquivos privados.

## 8. Regras de publicação e validação

O editor pode salvar rascunho incompleto. Para **publicar**, o backend deve recusar:

- Fluxo sem `Start` único e sem encadeamento válido a partir dele.
- Conexões com entradas/saídas inexistentes ou IDs repetidos.
- `Aguardar Resposta` sem campo ou prazo, ou sem os conectores `Respondeu` e `Não respondeu` no esquema do nó.
- `Condição` sem verificações ou sem os conectores `Sim` e `Não` no esquema do nó.
- `Ir para Fluxo` sem destino válido, publicado e do mesmo workspace.
- Referência direta ou indireta que crie ciclo entre fluxos por `Ir para Fluxo`.
- Mídias ou operações incompletas necessárias à execução.

**Ponto de UX:** apresentar mensagens de erro específicas no bloco correspondente, não apenas erro genérico de publicação.

### 8.1. Comportamento do grafo

- Blocos não ligados ao caminho iniciado em `Start` não devem executar.
- Avisar sobre nós desconectados; eles não bloqueiam a publicação e não executam.
- Fim de ramo sem próximo bloco conclui a execução normalmente.
- Um nó `Ir para Fluxo` é terminal: não pode possuir saída.
- O mecanismo de execução deve garantir que cada transição utilize um `handle_id` reconhecido pelo backend.

## 9. Cenários de aceite

| ID   | Cenário                                                            | Resultado esperado                                        |
| ---- | ------------------------------------------------------------------ | --------------------------------------------------------- |
| AC01 | Criar fluxo                                                        | Canvas inicia apenas com `Start`                          |
| AC02 | Clicar `+` na saída de `Start` e selecionar `Conteúdo`             | Bloco criado já conectado e painel esquerdo aberto        |
| AC03 | Adicionar texto, atraso e imagem num `Conteúdo`                    | Envio ocorre na ordem configurada                         |
| AC04 | `Aguardar Resposta` recebe mensagem antes do prazo                 | Salva no campo **do lead correto** e segue `Respondeu`    |
| AC05 | `Aguardar Resposta` atinge o prazo                                 | Não escreve valor inventado e segue `Não respondeu`       |
| AC06 | Resposta e timeout competem                                        | Apenas um caminho continua                                |
| AC07 | Condição `Todas (E)` para 3 etiquetas, lead possui 2               | Segue `Não`                                               |
| AC08 | Condição `Pelo menos uma (OU)`, lead possui 1 das 3                | Segue `Sim`                                               |
| AC09 | `Ir para Fluxo` vai para outro publicado                           | Encerra origem, inicia destino no `Start`, sem retorno    |
| AC10 | Fluxo A redireciona a B e B redireciona a A                        | Publicação é bloqueada com erro explicativo               |
| AC11 | Primeiro contato sem palavra-chave                                 | Executa boas-vindas **uma única vez**                     |
| AC12 | Primeiro contato com palavra-chave correspondente                  | Palavra-chave prevalece; boas-vindas não é enviada depois |
| AC13 | Contato antigo sem fluxo ativo nem palavra-chave                   | Inicia resposta padrão configurada                        |
| AC14 | Contato está aguardando resposta                                   | Resposta padrão **não** dispara                           |
| AC15 | Mensagem corresponde a duas regras                                 | Apenas a regra elegível mais acima na lista dispara       |
| AC16 | Administrador muda a ordem                                         | Novo desempate respeita imediatamente a ordem persistida  |
| AC17 | Regra `Contém` para `curso`                                        | `Quero um curso` corresponde                              |
| AC18 | Regra `É igual a` para `curso`                                     | `Quero um curso` não corresponde                          |
| AC19 | Palavra-chave com `Interromper = desligado` durante execução ativa | Não cancela a execução                                    |
| AC20 | Palavra-chave com `Interromper = ligado` durante execução ativa    | Cancela execução anterior e inicia a nova uma única vez   |
| AC21 | Automações pausadas                                                | Não dispara palavra-chave, boas-vindas ou resposta padrão |
| AC22 | Mover fluxo de pasta                                               | IDs/referências e regras associadas continuam válidos     |
| AC23 | Lead A responde a campo personalizado                              | Campo do lead B permanece inalterado                      |
| AC24 | Entrega repetida do mesmo webhook                                  | Não gera novo disparo duplicado                           |
| AC25 | Editar rascunho de fluxo publicado                                 | Versão em produção não muda até nova publicação           |

## 10. Regras aprovadas e estado da entrega

- O escopo é WhatsApp por conexão; simulador e canais adicionais ficam para outra etapa. Pastas aninhadas pertencem ao workspace, têm nomes únicos entre irmãs e não alteram IDs ou versões publicadas dos fluxos. Uma pasta não pode ser movida para si mesma ou para uma descendente.
- Campos personalizados são de texto e pertencem ao contato. A resposta de `Aguardar Resposta` substitui o valor anterior; áudio e anexos não preenchem a espera. O corpo continua fora do histórico local.
- Abrir/assumir atendimento humano cancela execução e pausa novos gatilhos. Finalizar libera somente a pausa humana. Pausa manual pode ser temporária (1 minuto a 7 dias) ou indefinida; a equipe autorizada na conversa pode encerrar pausa manual. Nenhuma pausa retoma uma execução antiga.
- A mensagem recebida durante fluxo ativo só o interrompe quando casa com palavra-chave marcada para interrupção. Fora de espera, outra mensagem não é enfileirada nem altera o fluxo.
- Se for primeiro contato e não houver palavra-chave, boas-vindas tem prioridade; sem boas-vindas, pode executar a resposta padrão. Saídas sem conexão encerram o ramo.
- Qualquer membro com `can_manage_flows`, além de donos e administradores, pode editar, publicar, desativar e configurar gatilhos.
- Atrasos de Conteúdo: 3–10 segundos, padrão 3. `Aguardar Resposta`: 1 minuto–7 dias, padrão 24 horas. No Conteúdo, falha definitiva ou conteúdo final inválido registra ocorrência e avança; resultado incerto é conciliado sem reenvio, com continuidade ao esgotar o orçamento. Menu conserva sua política.
- Publicações são versões imutáveis. Desativar impede inícios diretos; referências `Ir para Fluxo` em outros fluxos ativos precisam ser removidas antes de desativar o destino.

### 10.1. Verificação e aceite

O registro original de runtime inclui migration local e análise de tipos aprovada. Homologações posteriores registram envios reais parciais de Sequências, mídias, IA e Menu nos contratos correspondentes; não certificam o aceite manual completo da interface ou todos os cenários operacionais. Os cenários a conferir estão descritos na seção 9: criação e edição dos oito tipos de nó (Start, Conteúdo, Aguardar Resposta, Operações, Ir para Fluxo, Condição, IA e Menu), publicação, gatilhos, tempos de espera, pausas, uploads, concorrência e retomada após atendimento.

## 11. Entrega implementada

A primeira entrega visual incluiu lista de rascunhos, canvas Vue Flow, nós e conexões persistentes, edição dos textos, permissão por workspace e controle de revisão. Esta entrega acrescenta grafo v2 compatível com rascunhos v1, formulários completos, campos e etiquetas por contato, armazenamento privado de mídias, publicação/desativação, gatilhos, runtime em Redis/Horizon, resposta com timeout atômico e integração de pausa com o atendimento humano.

A UAZAPI permanece a fonte do histórico completo de mensagens. `Aguardar Resposta` grava o texto capturado no campo do contato escolhido. Com tempo real habilitado, prévias e conteúdo temporário criptografado seguem o [contrato do chat](../operacao/chat-realtime.md); jobs levam referências, e não corpos, sem criar histórico completo local.

## 12. Referências e fronteiras

- Vue Flow: <https://vueflow.dev/>
- [Chat e multiatendimento](chat-multiatendimento.md).
- [Integração UAZAPI](../integracoes/uazapi.md) e [documentação oficial](https://docs.uazapi.com/).
- O código existente do SaaS deve prevalecer sobre nomes e tabelas **sugeridos** aqui; adaptações estruturais devem ser documentadas antes de implementadas.
- Qualquer ampliação do escopo inicial deve atualizar esta especificação e marcar o que passou de **pendente** para **aprovado**.

## 13. Histórico de versões

- **v0.8 — 2026-10-13:** pastas aninhadas, navegação por caminho e menus de ações para pastas e fluxos; exclusão protegida de rascunhos nunca publicados.
- **v0.7 — 2026-10-12:** organização por pastas implementada com filtro, criação, renomeação, movimentação e exclusão segura que preserva os fluxos.
- **v0.6 — 2026-10-12:** configuração dos cinco blocos, mídias privadas, publicação imutável, gatilhos, executor Redis/Horizon e controle de pausa integrado ao atendimento implementados localmente; aceite manual e conexão UAZAPI contratada pendentes.
- **v0.5 — 2026-10-12:** corrigida a inserção contextual com conexão visível e persistente; saídas ocupadas exibem aviso sem criar blocos soltos.
- **v0.4 — 2026-10-12:** primeira entrega visual implementada: rascunhos, Vue Flow, texto no bloco Conteúdo, permissão por workspace e validação de conectores/revisão; publicação e executor permanecem pendentes.
- **v0.3 — 2026-10-12:** sincronizada entrada humana por gatilho, atores do atendimento e cadastro mínimo aprovado; mantidas pendências de pausa/retomada e recursos de CRM necessários ao executor.

- **v0.2 — 2026-10-12:** confirmados UAZAPI, um número por workspace, Vue 3 + Vue Flow e chat antes dos fluxos; registrada dependência da finalização humana e pendência sobre captura de respostas.
- **v0.1 — 2026-10-12:** especificação inicial do editor, blocos, executor e gatilhos.

## Integração com Sequências — 14/10/2026

O bloco Operações inclui **Adicionar à sequência** (`add_to_sequence`, `sequence_id`), com a saída normal. A inscrição ocorre imediatamente; o fluxo corrente continua e o primeiro relógio aguarda seu término. Inclusões repetidas da mesma operação são idempotentes.

`FlowExecutionManager` aplica exclusividade por workspace/conexão/contato a todos os gatilhos, incluindo etapas de sequência. Esperas de resposta, IA e atrasos mantêm a reserva. `Ir para Fluxo` transfere a reserva e a etapa sem liberar o contato; término idempotente registra motivo estruturado. Sequências fixam também as versões dos destinos encadeados na publicação.

Fluxos usados por sequência ativa ou participação vigente não podem ser desativados. Substituir a participação não cancela o fluxo já iniciado nem seus envios válidos. Detalhes e políticas em [Sequências](sequencias.md).

## Sessão temporária compartilhada e Assistente IA — 2026-10-14

O editor sincroniza mudanças manuais e do Assistente IA em uma prévia compartilhada, com revisão própria. Sincronização não equivale a Salvar: o rascunho `flows.graph` só é substituído pela ação explícita do usuário, e publicação continua independente. A prévia recupera trabalho após recarga/fechamento acidental. Descartar exige confirmação, afeta toda a equipe e restaura o rascunho salvo. Não há desfazer das alterações da IA.

Durante uma execução IA, edição manual e Salvar ficam pausados; Parar conserva lotes temporários concluídos. Conflitos não sobrescrevem mudanças locais silenciosamente. Contratos, catálogo central de capacidades, permissões e critérios estão em [Assistente IA de Fluxos](assistente-ia-fluxos.md).

## Documentos relacionados preservados

Consulte também a [briefing alternativo v0.4 com Menu](../historico/editor-de-fluxos-menu-v0.4.md). A organização dos arquivos não consolida automaticamente diferenças de conteúdo entre as versões.

## Bloco Menu — 14/10/2026

Implementado com Numérico, Botões e Lista no mesmo tipo de nó, saídas estáveis por opção e Continuar para prazo ou limite de erros. Numérico é o padrão: 5 minutos (1 minuto–7 dias), 3 erros (1–10), mensagem de erro editável e repetição sem renovar o prazo. `waiting_menu` mantém reserva exclusiva, respeita pausas, palavras-chave interruptivas e Sequências. Assistente IA cria/edita na prévia compartilhada. Botões/Lista exigem homologação por conexão antes de publicar. Contratos, liberação e cenários em [Bloco Menu](bloco-menu.md).

## Importação e exportação — 15/10/2026

O menu Ações do fluxo importa JSON por colagem/arquivo, exporta o rascunho salvo ou a versão publicada e baixa o guia Markdown para IA externa. A confirmação substitui e salva o rascunho e a prévia compartilhada; não muda nome, pasta, publicação ou gatilhos e não oferece desfazer. IA interna em fila/execução bloqueia a importação. Dependências portáteis de etiquetas/campos são reutilizadas/criadas após confirmação; mídias, atendentes, outros fluxos e sequências ficam pendentes conforme seu contrato. Ver [especificação implementada](importacao-exportacao-fluxos.md).

## Campos do contato — implementação de 15/10/2026

Texto/legenda de Conteúdo, mensagem/rótulo/destino de URL/Copiar e prompt IA oferecem **Inserir campo** com chips indivisíveis, pesquisa, histórico local e clipboard estruturado por workspace. Texto entre chaves digitado manualmente permanece literal. Canvas, recuperação e conflitos preservam o documento completo. A área de execuções oferece ocorrências paginadas e motivos seguros. Ver [contrato e evidências](campos-do-contato-fluxos.md). Publicação e execução com chips estão liberadas em todas as conexões desde 17/10/2026 por decisão do responsável, que fará os testes reais em produção.
