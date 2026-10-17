# Campos do contato nos fluxos

> **Liberação em 17/10/2026:** por decisão do responsável, publicação e execução estão liberadas em todas as conexões, inclusive produção, sem perfis ou variáveis de homologação. A validação real em produção será feita pelo responsável; esta liberação não acrescenta evidências de homologação. Os ensaios anteriores permanecem como histórico.

> O registro de implementação anterior ao ensaio com túnel é histórico; o resultado operacional está no fim desta página.

Implementação local em 15/10/2026. Naquela entrega não houve envios reais, chamadas OpenAI pagas nem homologação de substituições da UAZAPI. O ensaio de 16/10 encontrou uma incompatibilidade; a liberação foi posteriormente autorizada em 17/10, mantendo a incompatibilidade conhecida.

## Contrato

O grafo continua em `schema_version: 2`. Campos habilitados aceitam a string legada ou `ContactTemplate` v1:

```json
{
    "template_version": 1,
    "parts": [
        { "type": "text", "text": "Olá " },
        { "type": "standard", "key": "primeiro_nome" },
        { "type": "text", "text": ", seu curso é " },
        { "type": "custom", "field_id": 123 }
    ]
}
```

Partes têm propriedades estritas. Texto adjacente é unido sem remover espaços; um documento sem chips é normalizado para string. Somente o documento estruturado é interpretado: `{nome}`, `{{name}}` e outras chaves em texto comum permanecem literais no Laravel. Não há conversão de versões anteriores. Chips guardam IDs, nunca valores ou nomes como identidade.

- Nome: `contacts.display_name`, sem alteração.
- Primeiro nome: primeira palavra separada por espaços Unicode, sem coluna adicional.
- Telefone: `contacts.phone` original, sem formatação.
- Personalizados: definições existentes do workspace e valores do contato correspondente.

Ausência, `null` e referência excepcionalmente excluída resolvem para texto vazio. `"0"`, espaços e conteúdo preenchido são preservados. Não existem fallbacks. IDs novos inexistentes ou de outro workspace são rejeitados; uma referência antiga excepcionalmente ausente pode permanecer e resolver para vazio.

## Onde usar e editar

Permitido em texto e legenda do Conteúdo; mensagem, rótulo e destino dos botões URL/Copiar; prompt do bloco IA. Não permitido no elemento Botão Resposta inteiro, Menu, Operações, Condição, instruções dos recursos IA, nomes e descrições internas.

**Inserir campo** oferece pesquisa e grupos padrão/personalizados. A inserção substitui a seleção ou usa o cursor. O chip é indivisível, removível com Delete/Backspace; seu conteúdo não é editável. Nomes atuais vêm do catálogo. IDs distinguem nomes iguais no seletor e na identificação acessível. Não há sugestões ao digitar `{`.

O componente `ContactTemplateInput.vue` usa edição nativa e spans `contenteditable=false`, com histórico local por campo de texto, chips e seleção. A atualização da prévia não reinicia o histórico. Composição de texto, acentos e marcas de negrito/itálico/tachado permanecem disponíveis. O clipboard inclui texto simples e `application/x-chait-contact-template+json`, com workspace e partes. Colagens externas usam somente texto; HTML e arrastar conteúdo não são executados. Estruturas de outro workspace, referências indisponíveis e chips no Botão Resposta são recusados com explicação local. Remova chips da mensagem e do rótulo antes de trocar para Resposta.

Canvas, prévia, recuperação local e reaplicação de conflitos carregam o documento completo e mostram os nomes dos campos, sem consultar valores de contatos.

## Resolução e segurança

`ContactTemplate` centraliza validação, catálogo, referências, normalização e resolução. Uma única consulta PostgreSQL lê o contato e seus campos sob o mesmo snapshot, isolado pelo workspace. Todos os campos de uma mensagem usam essa leitura. Cada novo envio lê os valores atuais; não há congelamento do fluxo inteiro nem interpretação recursiva dos valores inseridos.

A validação estrutural ocorre no salvamento/publicação. Após resolver, o executor valida tamanho e regras de cada contexto. Mensagem, rótulo e destino obrigatórios vazios invalidam o item; legenda opcional vazia é permitida. Nada é truncado. Perfis de limites dos botões continuam aplicados após a expansão. Separadores `|` e controles em rótulos/cópia não podem criar escolhas adicionais.

URL composta mantém delimitadores literais, codifica dados de caminho/query/fragmento e preserva escapes percentuais válidos. Esquema e autoridade são validados, impedindo que dados de um componente introduzam outro. Um endereço formado por um único chip é validado como URL HTTP/HTTPS inteira, sem codificação integral. Percentuais malformados e URLs finais inválidas não são enviados.

No bloco IA, o snapshot é atualizado antes de cada chamada ao modelo, inclusive após ferramentas. Partes literais permanecem nas instruções; valores dos chips seguem em uma entrada separada de dados não confiáveis, com referências às partes. Esses valores não concedem ferramentas nem permissões. A regra de **não revelar dados pessoais do cadastro, nem parcialmente**, prevalece. A resposta do modelo é texto e nunca passa novamente pelo interpretador de templates.

## Continuidade e transporte

Para Conteúdo estático ou personalizado, conteúdo final inválido ou recusa confirmada gera ocorrência segura e avança para o próximo item e depois para **Próximo passo**. Não envia erro ao lead. Menu mantém sua política existente. Prompt personalizado inválido ou falha do envio da resposta IA encerra a sessão, segue **Continuar** e agenda limpeza remota. Falhas próprias da OpenAI, limites do turno, atendimento humano e pausas mantêm a política existente.

A intenção é persistida antes do transporte. `track_id` correlaciona consultas; não é garantia de deduplicação do provedor. Resultado incerto, timeout, erro de servidor, fila ou ausência de ID levam à conciliação, sem reconstruir, reenviar ou chamar novamente o modelo. Após 24 consultas, registra **resultado desconhecido** e continua. Registro e avanço usam o bloqueio por contato/conexão, conferindo execução/bloco/item ou sessão/track esperados. Confirmações tardias não reabrem etapas.

Respostas IA pendentes guardam somente sessão, track e número de consultas em `context.ai_pending_send`. O job `ReconcileAiSend` concilia sem corpo persistido. `flows:reconcile-sends`, agendado a cada minuto, recupera intenções de Conteúdo e IA após quedas, inclusive entre intenção e transporte. Intenção sem confirmação também termina como desconhecida, nunca é reenviada.

Ocorrências `send_failed`/`send_unknown` em `flow_events` incluem execução, versão, bloco, item/campo, horário, resultado e motivo seguro. Não incluem corpo renderizado, valores, credenciais ou payload completo. O editor oferece **Ocorrências das execuções**, com atualização e paginação. `GET /workspaces/{workspace}/flows/{flow}/events?before=ID` retorna até 50 registros e `next_before`, usando a mesma permissão do editor. A projeção de metadados da API é restrita.

## Exclusão e colaboração

A exclusão de campo verifica rascunhos salvos e prévias compartilhadas em todos os usos reconhecidos (chips, Condição, Aguardar Resposta, Operações e permissões IA), publicações atuais, execuções ativas, versões usadas por sequências ativas/participações em uso e mapeamentos de webhooks ativos. Histórico sem uso não bloqueia. A mensagem lista os fluxos responsáveis. Gravações manuais, assistente e exclusão usam a ordem workspace → fluxo/recurso. Revalidação sob bloqueio impede salvar referência após exclusão concorrente. Renomear preserva os IDs.

O assistente consulta `contact_fields` em `get_editor_capabilities`, usa o mesmo documento nos schemas estritos e patches e recebe pendências por campo. Não tem acesso aos valores reais dos contatos. IDs inventados/externos invalidam o lote inteiro. Plano aprovado, revisão, prévia compartilhada e publicação manual permanecem obrigatórios. O formato portátil converte `field_id` em `field_ref` declarado nas dependências e remapeia na importação; nenhum valor é exportado.

## Compatibilidade UAZAPI e liberação

O [OpenAPI oficial](https://docs.uazapi.com/openapi-bundled.json) documenta substituições nativas como `{{name}}`, `{{first_name}}` e `{{lead_field01}}`–`{{lead_field20}}`. O contrato consultado não oferece controle comprovado de desativação/escape. Resolver os chips no Laravel não demonstra que o provedor preserve o conteúdo final, inclusive texto literal, valores substituídos e respostas geradas pela IA.

Os perfis por conexão e `config/flow-contact-template.php` foram removidos. Publicação e preparação de envios/prompts com chips estão liberadas por padrão. O catálogo não apresenta `provider_compatibility`; editor e assistente não exigem homologação. Permanecem as validações de referências, permissões, URLs e limites após expansão.

O responsável fará os testes em produção. A UAZAPI pode substituir padrões nativos como `{{name}}` em texto e legenda, inclusive dentro dos valores dos chips, conforme o ensaio abaixo. Esta liberação não corrige essa alteração e não modifica silenciosamente o conteúdo preparado pelo Laravel.

## Evidências locais

Consultar [registro de verificação](../../artifacts/contact-template/verificacao.md). Testes locais e mocks verificam o comportamento do projeto; não certificam recebimento WhatsApp. Referências: [edição HTML](https://html.spec.whatwg.org/multipage/interaction.html#contenteditable), [Input Events](https://www.w3.org/TR/input-events-2/), [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986).

### Homologação local com túnel — 16/10/2026

O texto e a legenda preparados pelo Laravel continham padrões `{{...}}` literais, inclusive dentro de valores de chips sintéticos. A UAZAPI os substituiu antes da entrega, confirmado no iPhone. Rótulo, cópia e URL simples com chips foram preservados em ensaios separados, mas a configuração `verified` é por conexão e texto/legenda falharam. **A conexão permanece sem perfil de chips.** Veja [evidências sem corpos de mensagens](../../artifacts/tunnel-homologacao/resultado.md). Uma solução futura precisa preservar os literais sem alterar silenciosamente o conteúdo.

Notificações internas futuras estão [documentadas separadamente](../planejamento/notificacoes-operacionais-fluxos.md); não fazem parte desta entrega.
