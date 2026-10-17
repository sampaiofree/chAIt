# Bloco Conteúdo — Elemento Botão

> **Projeto:** chAIt — SaaS de atendimento e automações WhatsApp  
> **Documento:** especificação incremental do bloco Conteúdo  
> **Versão:** 1.0 — 14/10/2026  
> **Destino sugerido no repositório:** `docs/modulos/bloco-conteudo-elemento-botao.md`  
> **Status:** implementado localmente em 15/10/2026; homologação real da UAZAPI pendente. Contratos efetivos, compatibilidade com o projeto e verificação em [Conteúdo — Elemento Botão](../../modulos/bloco-conteudo-elemento-botao.md).  
> **Complementa:** `docs/modulos/editor-de-fluxos.md` e `docs/integracoes/uazapi.md`. Não substitui esses arquivos.

## 1. Objetivo

Ampliar o bloco **Conteúdo** para permitir um **elemento Botão** entre os elementos sequenciais já existentes (Texto, Imagem, Vídeo, Áudio, Arquivo e Atraso), preservando a simplicidade do bloco.

O elemento Botão inclui **sua própria mensagem de texto** e **exatamente um botão de ação**, de um destes tipos:

- **URL:** abre um endereço informado.
- **Resposta:** envia uma resposta do contato ao WhatsApp, para processamento normal do backend.
- **Copiar:** oferece uma ação de cópia de texto/código.

**Não incluir botão Ligação.** Também não adicionar botões de múltipla escolha, listas, saídas por opção ou espera embutida no Conteúdo. Para roteamento por escolha, permanece o bloco **Menu**.

## 2. Decisões funcionais aprovadas

| ID | Regra | Situação |
|---|---|---|
| CB-01 | O bloco Conteúdo pode ter **zero ou um** elemento Botão. | **Aprovada** |
| CB-02 | O elemento Botão traz consigo um **campo de texto da mensagem**; não depende de um elemento Texto separado. | **Aprovada** |
| CB-03 | O elemento oferece somente os tipos **URL**, **Resposta** e **Copiar**. | **Aprovada** |
| CB-04 | **Ligação** não será oferecida. | **Aprovada** |
| CB-05 | O elemento Botão participa da sequência ordenada de envio, como os outros elementos. | **Aprovada** |
| CB-06 | O bloco Conteúdo mantém **somente a saída `Próximo passo`** e **não aguarda cliques** em qualquer tipo de botão. | **Aprovada** |
| CB-07 | O clique em **Resposta** equivale a uma **mensagem recebida**, processada pelas regras existentes de automação e atendimento. | **Aprovada** |
| CB-08 | Se houver um bloco **Aguardar Resposta** ativo e elegível, o clique em Resposta pode ser capturado por ele conforme as regras daquele bloco. | **Aprovada** |
| CB-09 | Na ausência de uma espera ativa, a Resposta segue o processamento normal de mensagens e gatilhos; **não cria saída nem transferência automática**. | **Aprovada** |
| CB-10 | O editor deve mostrar orientação quando o tipo **Resposta** estiver selecionado. | **Aprovada** |

### Orientação obrigatória no editor para o tipo Resposta

> O clique envia uma resposta do contato. Para aguardar essa resposta, utilize o bloco Aguardar Resposta. Para criar caminhos diferentes, utilize o bloco Menu.

## 3. Experiência no editor visual

### 3.1. Adição do elemento

No painel lateral de edição do bloco **Conteúdo**:

1. O administrador clica em **Adicionar elemento**.
2. Seleciona **Botão**.
3. O editor insere na posição escolhida um **único elemento com texto + botão**.
4. O administrador configura tipo, rótulo e ação.
5. O elemento pode ser reordenado com os demais elementos do Conteúdo.

Se já existir um elemento Botão no bloco, **desabilitar/ocultar a opção de adicionar outro** e validar a mesma restrição no backend. Remover o elemento libera nova inclusão.

### 3.2. Campos de configuração

| Campo | Obrigatoriedade | Observação |
|---|---|---|
| **Texto da mensagem** | Obrigatório | Texto enviado junto com o botão; usar a experiência de texto simples do Conteúdo, respeitando o que a UAZAPI homologar. |
| **Tipo de botão** | Obrigatório | `URL`, `Resposta` ou `Copiar`. |
| **Texto do botão** | Obrigatório | Rótulo visível para o lead. |
| **Endereço URL** | Obrigatório se URL | Link de destino; recomendar validar `https://` e `http://` com regras de segurança. |
| **Identificador da resposta** | Necessário tecnicamente se Resposta | Gerado e preservado pelo sistema; não precisa ser editável pelo usuário. |
| **Texto para copiar** | Obrigatório se Copiar | Texto/código transferido pelo botão de cópia. |

**UX recomendada:** exibir somente o campo de destino referente ao tipo selecionado, manter uma prévia do elemento e apresentar mensagens de validação próximas aos campos. Comprimentos máximos de texto/rótulo devem seguir os limites homologados do provedor, sem números arbitrários no contrato de produto.

### 3.3. Exemplo de sequência no Conteúdo

```text
Bloco Conteúdo
  1. Texto: "Veja nossos cursos profissionalizantes."
  2. Atraso: 2 segundos
  3. Botão:
       Mensagem: "Quer conhecer os cursos disponíveis?"
       Tipo: URL
       Rótulo: "Ver cursos"
       Destino: https://exemplo.com/cursos
  4. Texto: "Se precisar, seguimos por aqui."

Saída única → Próximo passo
```

O elemento **Botão** representa **um único envio interativo** contendo sua mensagem e seu botão. O texto incluído nesse elemento não deve ser duplicado como envio separado.

## 4. Execução e tratamento de cliques

| Tipo | No envio | Depois do envio | Quando o usuário interage |
|---|---|---|---|
| **URL** | Envia texto + botão de link. | A execução segue a ordem de elementos e depois `Próximo passo`. | Abre endereço no cliente WhatsApp; **não presumir webhook confirmando clique**. |
| **Copiar** | Envia texto + botão de cópia. | A execução segue normalmente. | Copia o valor no cliente WhatsApp; **não presumir confirmação de cópia na API**. |
| **Resposta** | Envia texto + botão com ID de resposta. | A execução segue normalmente, **sem esperar**. | O evento de resposta, quando recebido, entra no pipeline padrão de mensagens do contato. |

### 4.1. Regra de processamento para Resposta

A mensagem recebida deve respeitar a prioridade e os estados já definidos no editor e no chat:

1. Confirmar **workspace, conexão, contato e evento recebido**, com idempotência.
2. Se a conversa estiver sob **atendimento humano ativo**, a mensagem não reativa a automação.
3. Se houver **palavra-chave elegível para interromper uma automação**, aplicar a precedência já aprovada.
4. Se houver um **Aguardar Resposta** ativo e a mensagem for elegível para consumi-lo, aplicar sua regra de captura e timeout atômico.
5. Sem espera ativa, aplicar as regras gerais do estado atual (fluxo ativo, palavras-chave, boas-vindas, resposta padrão etc.); **não inventar roteamento próprio do Conteúdo**.

A correspondência entre o ID interativo e o texto legível deve ocorrer **no adaptador de mensagens**, quando necessário. A forma exata de valor capturado em campo personalizado (rótulo ou valor normalizado) depende da regra existente de captura do bloco Aguardar Resposta e deve ser validada antes da implementação — não presumir que o campo da UAZAPI será sempre o texto visível.

**Importante:** um clique de Resposta realizado horas depois continua sendo uma nova mensagem recebida; o elemento Botão **não mantém uma espera aberta** nem pode reabrir automaticamente uma execução antiga.

### 4.2. Janela de concorrência (risco técnico)

Fluxo de exemplo: `Conteúdo (Resposta) → Aguardar Resposta`.

O contato pode clicar logo após receber o botão, **antes** de o backend ativar a espera do bloco seguinte. Sem coordenação, a resposta pode não ser capturada.

**Requisito de engenharia:** tratar consistentemente mensagens recebidas e avanço do executor para o mesmo contato/conexão, sem perder evento válido por condição de corrida. Solução concreta (serialização por contato, registro da espera antes de liberar o envio ou correlação/buffer seguro) deve respeitar as regras já existentes e a decisão de **não armazenar histórico completo de mensagens no Laravel**. Não escolher um mecanismo definitivo apenas com este briefing.

### 4.3. Limites operacionais

- Não criar uma saída extra de erro, sucesso, clique ou timeout no Conteúdo.
- Não associar URL/Copiar a uma ação interna sem evento confiável que a comprove.
- Não presumir que toda Resposta contenha o mesmo campo em todos os webhooks; validar payload da UAZAPI contratada.
- Não tentar reenviar automaticamente diante de timeout HTTP de resultado incerto, pois isso pode duplicar o envio.
- Antes de disparar um elemento, revalidar **execução ativa, versão, workspace e pausas por atendimento humano**, inclusive após atraso.
- Eventos recebidos em duplicidade não podem gerar consumo duplo de espera ou duas execuções novas.

## 5. Integração com a UAZAPI — suporte documental e testes

A documentação pública da UAZAPI descreve o endpoint **`POST /send/menu`** com `type: "button"` e `choices` para as três ações do produto. A decisão de permitir **apenas um botão no Conteúdo** é uma restrição do chAIt, não uma limitação afirmada do provedor.

| Tipo chAIt | Formato documentado na UAZAPI (`choices` com um item) | Observação |
|---|---|---|
| URL | `"Ver cursos|url:https://exemplo.com/cursos"` | A documentação também aceita a URL diretamente após `|`. |
| Resposta | `"Tenho interesse|interesse_id"` | Gerar ID interno próprio, estável no contexto necessário. |
| Copiar | `"Copiar cupom|copy:CUPOM10"` | O texto após `copy:` é o conteúdo a copiar. |

Exemplo **ilustrativo** de payload de URL:

```json
{
  "number": "5511999999999",
  "type": "button",
  "text": "Conheça nossos cursos.",
  "choices": ["Ver cursos|url:https://exemplo.com/cursos"]
}
```

**Atenção à implementação:** construir `choices` a partir dos campos validados, sem concatenar valores não validados que contenham separadores reservados (`|`, quebra de linha) ou prefixos especiais. Proteger as credenciais da instância no Laravel. O exemplo não é confirmação de envio real.

### Riscos documentados pelo fornecedor

- Botões interativos podem sofrer indisponibilidade/descontinuação pela dependência de compatibilidade com WhatsApp.
- Misturar tipos interativos diferentes **na mesma mensagem** pode impedir visualização no WhatsApp Web. A restrição de **um botão por bloco** evita essa mistura dentro do elemento.
- A presença de `buttonOrListid` no modelo de mensagem documentado **não comprova sua presença em todo webhook real**. Confirmar a identificação da Resposta na instância contratada.
- Não presumir telemetria de clique dos botões URL e Copiar.

**Referências oficiais:**

- [Enviar menu interativo (`POST /send/menu`)](https://docs.uazapi.com/endpoint/post/send~menu)
- [Eventos `messages` (webhook)](https://docs.uazapi.com/webhook/messages)
- [Modelo de mensagem e `buttonOrListid`](https://docs.uazapi.com/endpoint/post/message~find)
- [Erros e confiabilidade](https://docs.uazapi.com/docs/errors-and-retries)

**Status da verificação:** contrato público consultado em 14/10/2026; **não houve teste em instância real do cliente**.

## 6. Persistência — proposta de contrato lógico

Preservar a estrutura sequencial de elementos existente no Conteúdo. **Não criar outro bloco**, mecanismo de roteamento ou tabela de histórico de mensagens por causa do botão.

Exemplo de **estrutura ilustrativa**, não migration definitiva:

```json
{
  "type": "content",
  "items": [
    { "id": "item_text_1", "type": "text", "text": "Olá!" },
    {
      "id": "item_button_1",
      "type": "button",
      "text": "Deseja receber mais informações?",
      "button": {
        "kind": "reply",
        "label": "Tenho interesse",
        "reply_id": "reply_interesse_1"
      }
    }
  ]
}
```

Requisitos técnicos recomendados:

- Validar no **backend** que o total de itens `type: button` por nó `content` é no máximo **1**.
- Gerar identificadores estáveis que sobrevivam a reordenação e salvamento, sem usar o rótulo como chave primária.
- Manter compatibilidade de leitura e execução dos blocos Conteúdo antigos, que não têm o elemento Botão.
- Respeitar versões publicadas: editar ou excluir o botão em rascunho **não altera silenciosamente** execuções já iniciadas.
- Validar campos condicionais do botão ao publicar e também no envio, para impedir payloads inválidos por dados legados.

## 7. Critérios de aceite

| ID | Cenário | Resultado esperado |
|---|---|---|
| CB-AC01 | Abrir um Conteúdo e adicionar elemento Botão | Formulário exibe texto da mensagem, tipo, rótulo e campo de ação correspondente. |
| CB-AC02 | Adicionar segundo elemento Botão ao mesmo Conteúdo | Editor impede e backend rejeita. |
| CB-AC03 | Criar botão URL e executar fluxo | Uma mensagem interativa é enviada e o Conteúdo continua sem esperar clique. |
| CB-AC04 | Criar botão Copiar e executar fluxo | Uma mensagem interativa é enviada e o Conteúdo continua sem esperar cópia. |
| CB-AC05 | Criar botão Resposta | Editor exibe aviso aprovado; bloco conserva uma única saída. |
| CB-AC06 | Clicar Resposta com Aguardar Resposta ativo | Uma única mensagem elegível é capturada conforme a regra do bloco Aguardar Resposta. |
| CB-AC07 | Clicar Resposta sem Aguardar Resposta ativo | Mensagem passa pelo processamento normal, sem criar uma ramificação do Conteúdo. |
| CB-AC08 | Reordenar elementos | Mantém ordem exata de envio e vínculo entre texto e botão. |
| CB-AC09 | Tentar publicar URL, rótulo ou texto obrigatórios vazios | Publicação falha com indicação do campo inválido. |
| CB-AC10 | Editar ou remover botão de um rascunho publicado | Versão executada anteriormente permanece consistente. |
| CB-AC11 | Atendimento humano interrompe/pausa a automação antes do envio | Envio automático do Botão não ocorre enquanto a pausa for aplicável. |
| CB-AC12 | Webhook duplicado ou resposta simultânea ao início da espera | Não duplica captura/transição; não perde resposta elegível por condição de corrida. |
| CB-AC13 | Texto do botão contém separador reservado ou valor inválido | Backend rejeita ou faz codificação segura compatível com o provedor, sem criar botão adicional. |
| CB-AC14 | Testar URL, Copiar e Resposta em clientes WhatsApp reais | Compatibilidade e payloads recebidos são registrados; recurso só é habilitado em produção após homologação suficiente. |

## 8. Fora do escopo desta alteração

- Botão de **Ligação**.
- Mais de um botão dentro do mesmo bloco Conteúdo.
- Botões com múltiplas saídas ou espera de clique dentro do Conteúdo.
- Listas, menus numéricos e lógica de tentativa/timeout: responsabilidade do bloco **Menu**.
- Rastreamento garantido de clique em URL ou Copiar, sem suporte documentado e homologado.
- Novos modelos de banco ou armazenamento de histórico integral do WhatsApp.
- Personalização avançada de cabeçalhos com mídias nos botões; embora a API documente opções, **não foram solicitadas/aprovadas neste escopo**.

## 9. Pendências estritamente técnicas

1. Homologar os **três tipos de envio** na instância UAZAPI contratada, incluindo renderização no WhatsApp Web e mobile.
2. Verificar o **payload real** de Resposta no webhook, mapeamento do ID e normalização para o bloco Aguardar Resposta.
3. Definir solução de engenharia para a **corrida entre o clique imediato e a ativação do Aguardar Resposta**, sem contrariar a política de retenção.
4. Fixar comprimentos e caracteres aceitos conforme limites efetivos da instância/cliente WhatsApp, não por suposição.

---

**Histórico:** v1.0 (14/10/2026) — inclusão de um elemento Botão com texto obrigatório, opções URL/Resposta/Copiar, ausência de Ligação, saída única do Conteúdo e processamento normal de Resposta; registro de testes e riscos operacionais.
