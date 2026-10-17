# Bloco Condição — Especificação funcional e técnica

> Revisão documental em 16/10/2026, confrontada com o código; não representa nova homologação operacional.

> **Projeto:** chAIt — SaaS de atendimento e automações  
> **Módulo:** Editor visual de fluxos  
> **Documento canônico no repositório:** `docs/modulos/bloco-condicao.md`  
> **Versão:** 1.1 — 14/10/2026  
> **Estado:** implementado localmente; testes automatizados com dados sintéticos e respostas de IA simuladas.  
> **Documento relacionado:** [Editor de fluxos](editor-de-fluxos.md)

## 1. Objetivo

Permitir que um fluxo escolha um de dois caminhos com base nos **dados do contato (lead)**: etiquetas, campos do contato ou uma combinação das duas categorias. O bloco apenas consulta informações; não altera o contato, não envia mensagens e não executa operações.

Manter o editor simples: um bloco Condição tem **uma entrada**, **duas saídas fixas** (`Sim` e `Não`) e **um operador lógico geral** (`Todas (E)` ou `Pelo menos uma (OU)`). Para expressões mais complexas, o administrador conecta outros blocos Condição.

## 2. Decisões de produto aprovadas

| Aspecto                     | Regra aprovada                                                              |
| --------------------------- | --------------------------------------------------------------------------- |
| Fontes                      | Etiquetas e campos do contato, isolados por workspace                       |
| Uso                         | Somente etiquetas, somente campos ou combinação no mesmo bloco              |
| Verificação de etiqueta     | `Possui` / `Não possui`                                                     |
| Verificação de campo        | `É igual a` / `Contém`                                                      |
| Combinação das verificações | `Todas (E)` / `Pelo menos uma (OU)`; um único operador para o bloco inteiro |
| Saídas                      | Exatamente `Sim` e `Não`                                                    |
| Correspondência de texto    | Ignora maiúsculas/minúsculas e acentos; remove espaços nas extremidades     |
| Campo sem valor             | Não satisfaz nenhuma verificação de campo                                   |
| Regras avançadas            | Utilizar vários blocos; sem agrupamentos aninhados `E/OU` no próprio bloco  |
| Exclusão de referências     | Impedir excluir etiquetas e campos usados por fluxos publicados             |

## 3. Verificações disponíveis

### 3.1. Etiquetas

Cada verificação aponta para **uma etiqueta** cadastrada no workspace e tem um dos operadores:

- **Possui:** verdadeiro quando a etiqueta está vinculada ao contato.
- **Não possui:** verdadeiro quando a etiqueta não está vinculada ao contato.

É permitido adicionar várias verificações de etiquetas ao mesmo bloco.

### 3.2. Campos do contato

Cada verificação aponta para **um campo** e contém um operador e a expressão procurada:

- **É igual a:** verdadeiro quando o valor completo do campo corresponde à expressão, após normalização.
- **Contém:** verdadeiro quando a expressão aparece em qualquer posição do valor do campo, após normalização.

Na primeira versão, os campos elegíveis são:

- Campos padrão de texto já existentes no cadastro: **nome, e-mail e CPF**.
- Campos personalizados **do tipo texto**, cadastrados no workspace.

**Notas/observações livres**, notas internas, conteúdo do histórico do WhatsApp e campos de tipos futuros não entram automaticamente nesse seletor. Ampliar o catálogo exige decisão e contrato específicos.

A expressão pesquisada é **obrigatória** para os dois operadores; uma expressão vazia ou composta apenas de espaços torna a verificação inválida. Se o campo selecionado estiver ausente, `null`, vazio ou contiver somente espaços, o resultado da verificação de campo é **falso**, inclusive no operador `É igual a`.

### 3.3. Comparação de texto

Ambos os lados da comparação (valor do contato e expressão cadastrada) devem:

1. Ser tratados como texto.
2. Ter espaços **nas extremidades** removidos.
3. Ser comparados sem diferença entre maiúsculas/minúsculas e acentos, com normalização Unicode consistente.

**Não** eliminar palavras, pontuação, máscaras de CPF ou espaços internos por suposição. `Contém` corresponde a um trecho textual contínuo, **não** a palavra inteira nem a similaridade semântica.

Exemplos:

| Valor do campo                          | Operador    | Expressão | Resultado |
| --------------------------------------- | ----------- | --------- | --------- |
| `Quero fazer um curso de inglês online` | `Contém`    | `inglês`  | `Sim`     |
| `Quero fazer um curso de inglês online` | `É igual a` | `inglês`  | `Não`     |
| `Vitória`                               | `É igual a` | `vitoria` | `Sim`     |
| `Mariana`                               | `Contém`    | `ana`     | `Sim`     |
| Vazio                                   | `Contém`    | `curso`   | `Não`     |

> **Alerta para a interface:** `Contém` também encontra substrings em outras palavras. Por exemplo, `ana` corresponde a `Mariana`. Não inserir interpretação automática ou IA nessa regra.

## 4. Combinação e seleção da saída

Cada bloco contém uma lista **não vazia** de verificações, que podem misturar etiquetas e campos. Escolhe-se **um** operador para a lista inteira:

- **Todas (E):** seguir por `Sim` somente quando **todas** as verificações forem verdadeiras.
- **Pelo menos uma (OU):** seguir por `Sim` quando **qualquer** verificação for verdadeira.
- Em todos os demais casos válidos, seguir por `Não`.

Com apenas uma verificação, `E` e `OU` produzem o mesmo resultado. As saídas devem manter os IDs técnicos estáveis, independentemente da quantidade e da ordem das verificações.

**Exemplo misto:**

- Operador do bloco: `Todas (E)`.
- Verificação 1: etiqueta `Cliente VIP` → `Possui`.
- Verificação 2: campo `Cidade` → `É igual a` → `Vitória`.
- Verificação 3: campo `Interesse` → `Contém` → `inglês`.

O fluxo seguirá por `Sim` **somente** quando as três verificações forem verdadeiras. Se uma falhar, seguirá por `Não`.

Para construir `(A E B) OU (C E D)`, usar **mais de um bloco Condição** e conectar as saídas. Não criar agrupamentos, precedências individuais ou um editor de expressões nesta versão.

## 5. Interface do bloco no Vue Flow

No canvas, exibir o nome configurável do bloco e uma síntese curta, por exemplo: `Condição · 3 verificações · Todas (E)`.

Ao selecionar o bloco, abrir o **painel lateral esquerdo** já previsto no editor, com:

1. Seletor **Combinar verificações**: `Todas (E)` ou `Pelo menos uma (OU)`.
2. Cartões numerados, com controles empilhados, sem reordenação nesta versão. A ordem **não altera** o resultado lógico.
3. Em cada linha, seletor de **tipo**: `Etiqueta` ou `Campo do contato`.
4. Para etiqueta: selecionar uma etiqueta do workspace e o operador `Possui`/`Não possui`.
5. Para campo: selecionar um campo elegível, escolher `É igual a`/`Contém` e preencher a expressão.
6. Controles **Adicionar verificação** e **Remover verificação**.
7. Mensagens de validação diretamente na linha com problema.

O componente no canvas mantém **uma entrada** e as saídas **`Sim`** e **`Não`**, ambas com rótulos visíveis. Adicionar/remover verificações **não** cria/remover conectores nem modifica ligações existentes.

**Implementado:** iniciar um bloco recém-adicionado sem verificações e indicar claramente que ele precisa de ao menos uma antes de publicar; o rascunho pode ser salvo incompleto.

## 6. Contrato entre editor e executor

O grafo mantém `schema_version: 2`. O nó usa o contrato abaixo, com IDs inteiros de recursos do workspace. Salvar **IDs estáveis**, nunca nomes exibidos como referência.

```json
{
    "id": "node-42",
    "type": "condition",
    "position": { "x": 300, "y": 250 },
    "data": {
        "label": "Verificar contato",
        "condition_operator": "all",
        "checks": [
            { "kind": "tag", "tag_id": 10, "operator": "not_has" },
            {
                "kind": "field",
                "field_id": 20,
                "operator": "contains",
                "value": "inglês"
            },
            {
                "kind": "field",
                "field_key": "display_name",
                "operator": "equals",
                "value": "Ana"
            }
        ]
    }
}
```

- `condition_operator`: `all` (`Todas`) ou `any` (`Pelo menos uma`).
- `checks`: de 1 a 50 verificações na publicação; rascunhos podem estar incompletos.
- `kind=tag`: `operator=has|not_has` e `tag_id` inteiro obrigatório; sem referência de campo ou expressão.
- `kind=field`: `operator=equals|contains`, expressão `value` com até 4.000 caracteres e exatamente uma referência: `field_id` para personalizado de texto ou `field_key=display_name|email|cpf` para padrão.
- Handles `input`, `yes` e `no` vêm do catálogo e não são armazenados como uma lista editável em cada nó. Uma saída sem conexão encerra o ramo, conforme o editor.
- O backend valida esquema, operadores, tipos e pertinência ao workspace. Não aceita coluna arbitrária em `field_key`.
- Nós antigos com `tag_ids` são convertidos para `checks` com operador `has`. A conversão é idempotente; versões publicadas armazenadas não são reescritas.
- Normalização é aplicada ao editor, estado do assistente, prévia, salvamento, publicação e leitura pelo executor.

## 7. Comportamento do executor Laravel

1. Ao alcançar o bloco, identificar a **execução**, o **workspace** e o **contato** autorizados.
2. Ler etiquetas e valores dos campos desse contato no **estado atual**, idealmente de forma consistente para todas as verificações daquele bloco.
3. Avaliar cada verificação e aplicar `Todas (E)` ou `Pelo menos uma (OU)`.
4. Persistir a transição da execução e avançar **uma única vez** pela saída `Sim` ou `Não`.
5. Não escrever em etiquetas, campos, mensagens nem estado de atendimento por efeito deste bloco.

As informações são examinadas **quando o fluxo passa pelo bloco**; alterar o campo depois disso não muda retroativamente o caminho escolhido. O executor deve respeitar a versão publicada do fluxo e impedir que jobs duplicados avancem a execução duas vezes.

**Tratamento de erros implementado:** a ausência de valor no contato é uma condição de negócio e resulta em `falso` para verificações de campo. Já configurações corrompidas, recursos excluídos irregularmente ou falha de acesso ao banco **não** devem ser interpretados como `Não`: interromper/falhar o passo com diagnóstico técnico, sem disparar outro ramo por engano.

**Segurança e privacidade:** consultas devem impor `workspace_id` e contato correto no Laravel. Logs operacionais podem registrar IDs e saída escolhida, mas **não** devem registrar automaticamente CPF, e-mail nem o conteúdo dos campos avaliados.

## 8. Proteção contra exclusão de campos e etiquetas

**Decisão aprovada:** impedir a exclusão de **etiquetas** e **campos personalizados** referenciados por **fluxos publicados**.

Ao solicitar exclusão:

1. O backend deve verificar se o recurso aparece em qualquer bloco Condição das versões **publicadas vigentes** no mesmo workspace.
2. Se houver referência, **recusar a exclusão**, explicando que o recurso está em uso por uma automação publicada.
3. Só permitir excluir após remover/substituir todas as referências e publicar a versão corrigida do(s) fluxo(s), ou após deixar de existir versão publicada que dependa do recurso.
4. A validação deve ocorrer no backend **na operação de exclusão**, não apenas desabilitando o botão na interface.

**Proteção implementada para execuções antigas:** proteger também recursos ainda necessários a **execuções ativas** de versões anteriores, mesmo que uma nova versão tenha sido publicada. Verificar referências/execuções sob proteção contra concorrência, para que publicação e exclusão simultâneas não deixem uma referência inválida.

**Interface implementada:** indicar qual(is) fluxo(s) publicado(s) utiliza(m) o campo/etiqueta; caso a lista não esteja pronta, mostrar ao menos uma mensagem objetiva, por exemplo: `Não é possível excluir: este campo é utilizado em um fluxo publicado.`

A exclusão física foi aprovada nesta entrega. Administradores encontram **Excluir** no cadastro de campos e etiquetas; o diálogo informa que os valores do campo ou os vínculos da etiqueta serão removidos definitivamente. A proteção distingue o recurso: **campos personalizados** também são protegidos quando referenciados em rascunhos salvos ou prévias compartilhadas, inclusive em Condição, Aguardar Resposta, Operações, recursos IA e chips. Para **etiquetas**, referências apenas em rascunhos/prévias não bloqueiam; a publicação exige corrigir a pendência. Campos padrão não têm ação de exclusão. O serviço `ContactResourceReferences` aplica essa verificação sob o bloqueio do workspace.

A proteção também cobre `Aguardar Resposta`, `Operações`, permissões de recursos do bloco IA, versões preservadas por sequências ativas ou participações não encerradas e webhooks ativos. As mensagens identificam fluxos/versões, sequências e webhooks envolvidos. Publicação e exclusão compartilham o bloqueio de workspace e verificam novamente as referências dentro da transação. Reativação de sequência também revalida os recursos das versões preservadas.

## 9. Validação de rascunho e publicação

**Salvar rascunho:** aceitar configuração parcial com indicação visual de pendências, conforme regra já definida no editor.

**Publicar:** bloquear nos blocos alcançáveis a partir de Start quando houver:

- Nenhuma verificação configurada.
- Operador geral inválido ou ausente.
- Verificação de etiqueta sem etiqueta, sem operador válido ou com etiqueta inexistente/de outro workspace.
- Verificação de campo sem campo elegível, sem operador válido, ou com expressão vazia após `trim`.
- Campo personalizado inexistente, de outro workspace ou de tipo não suportado.
- Contrato do nó incorreto: falta de uma entrada ou handles de saída `Sim`/`Não`.
- Conexões estruturais inválidas, conforme as regras gerais do editor. Nós desconectados não executam nem bloqueiam a publicação.

As mensagens de erro devem identificar o **bloco** e a **verificação** que precisa de correção. Validar tudo também no backend; validação Vue não substitui validação de domínio.

## 10. Critérios de aceite e cenários de teste

| ID      | Cenário                                                             | Resultado esperado                                              |
| ------- | ------------------------------------------------------------------- | --------------------------------------------------------------- |
| COND-01 | Verificar `Possui` com etiqueta presente                            | Verificação verdadeira                                          |
| COND-02 | Verificar `Não possui` com etiqueta ausente                         | Verificação verdadeira                                          |
| COND-03 | `Todas (E)` com três verificações, uma falsa                        | Saída `Não`                                                     |
| COND-04 | `Pelo menos uma (OU)` com três verificações, uma verdadeira         | Saída `Sim`                                                     |
| COND-05 | Combinar etiqueta e campo no mesmo bloco                            | Todas as verificações participam do operador geral              |
| COND-06 | Campo `Interesse` contém `inglês` dentro de uma frase               | Saída conforme correspondência verdadeira                       |
| COND-07 | Campo `Interesse` é igual a `inglês`, mas contém frase maior        | Verificação falsa                                               |
| COND-08 | `Vitória` comparado com `vitoria`                                   | Correspondência verdadeira nos dois operadores quando aplicável |
| COND-09 | Campo vazio ou ausente com `É igual a`/`Contém`                     | Verificação falsa                                               |
| COND-10 | Expressão apenas com espaços                                        | Publicação bloqueada                                            |
| COND-11 | Bloco sem verificações                                              | Pode salvar rascunho; publicação bloqueada                      |
| COND-12 | Campo/etiqueta pertence a outro workspace                           | Publicação/executação bloqueada; sem vazamento de dados         |
| COND-13 | Tentar excluir campo personalizado usado em fluxo publicado         | Exclusão recusada no backend                                    |
| COND-14 | Tentar excluir etiqueta usada em fluxo publicado                    | Exclusão recusada no backend                                    |
| COND-15 | Remover referência, publicar ajuste e não haver execução dependente | Exclusão liberada conforme política geral de recursos           |
| COND-16 | Reprocessar job de um bloco já concluído                            | Não avança duas vezes nem duplica o caminho                     |
| COND-17 | Remover ou adicionar verificações                                   | Saídas e ligações existentes permanecem intactas                |
| COND-18 | `Contém` com `ana` e valor `Mariana`                                | Verificação verdadeira (substring)                              |
| COND-19 | Falha de banco ao consultar o campo                                 | Não seguir automaticamente pela saída `Não`; registrar falha    |

## 11. Fora do escopo desta versão

- Grupos aninhados, múltiplos operadores lógicos em um mesmo bloco ou linguagem de expressões.
- Operadores de campo além de `É igual a` e `Contém`.
- Comparação numérica, por data, expressões regulares, intervalo de valores ou correspondência aproximada/IA.
- Consulta a conteúdo do histórico de mensagens, notas internas ou dados de outros contatos.
- Atualização de dados, aplicação de etiquetas ou envio de mensagens diretamente no bloco.
- Terceira saída de `Erro`: falhas técnicas são tratadas pelo executor, não confundidas com `Não`.

## 12. Assistente IA do editor

O assistente pode criar e editar condições mistas e conectar as saídas `yes` e `no`, usando o mesmo catálogo e regras do editor. Consulta apenas definições de etiquetas/campos; `standard_fields` lista Nome, E-mail e CPF. Não recebe valores reais de contatos. Recursos inexistentes viram pendências para seleção/criação manual; a IA não inventa IDs nem cria recursos externos.

`apply_flow_patch` aceita `checks` no schema estrito; `validate_flow` retorna `node_id`, `path` e mensagem da verificação pendente. O processo mantém aprovação do plano, prévia compartilhada, recuperação e salvamento/publicação humanos. Operadores e comparação são determinísticos, sem interpretação semântica pela IA.

## 13. Verificação e documentação

- Cenários funcionais e proteção: `tests/Feature/Chait/FlowConditionTest.php`.
- Ferramentas reais com respostas OpenAI simuladas: `tests/Feature/Chait/FlowAssistantTest.php`.
- Cartões, recarga, publicação, exclusão, teclado e ambos os temas em 390–1440 px: `tests/Browser/condition.spec.ts`.
- Concorrência real PostgreSQL em schema isolado: `APP_ENV=testing php tests/Concurrency/condition-resources.php`.
- A normalização Unicode requer as extensões PHP `intl` e `mbstring`, declaradas no Composer e na CI.

A seção 5.6 do editor, o catálogo do assistente, o índice e o registro de decisões apontam para esta especificação. Testes usam dados sintéticos, banco de teste isolado e respostas simuladas; não fazem chamadas pagas à OpenAI nem enviam WhatsApp.

### Evidências da validação local — 14/10/2026

- Suíte PHP: **406 testes, 2.071 assertions**, todos aprovados.
- Navegador: cartões, teclado, recarga da prévia, mensagens de publicação, exclusão permitida/protegida, assistente e regressão de layout nos dois temas. A expectativa antiga “Escolha as etiquetas” foi atualizada para “Adicione uma verificação” e revalidada.
- Concorrência PostgreSQL: publicação/exclusão testadas com processos independentes nos dois sentidos; suíte de concorrência existente do assistente também aprovada.
- Tipos, ESLint, Prettier, Pint, validação Composer e build aprovados.
- Extensões `intl` e `mbstring` presentes no ambiente; nenhuma migration nova. Nenhuma API paga ou mensagem WhatsApp foi usada na validação.

Capturas para revisão: [desktop claro](../../artifacts/bloco-condicao/desktop-claro.png), [desktop escuro](../../artifacts/bloco-condicao/desktop-escuro.png), [celular claro](../../artifacts/bloco-condicao/celular-claro.png) e [celular escuro](../../artifacts/bloco-condicao/celular-escuro.png). Os cinco cenários novos de navegador passaram na rodada final; a regressão de editor/assistente/layout também foi verificada.
