<?php

namespace App\Services\Flows;

class FlowTransportGuide
{
    public function examples(): array
    {
        $node = fn ($id, $type, $data) => ['id' => $id, 'type' => $type, 'data' => $data];
        $edge = fn ($from, $output, $to) => ['source' => $from, 'output' => $output, 'target' => $to];
        $envelope = fn ($name, $nodes, $edges, $tags = [], $fields = []) => ['format' => 'chait-flow', 'schema_version' => '1.0', 'name' => $name, 'dependencies' => ['tags' => $tags, 'fields' => $fields], 'nodes' => $nodes, 'edges' => $edges];

        return [
            $envelope('Boas-vindas e coleta de nome', [
                $node('inicio', 'start', ['label' => 'Início']),
                $node('pergunta', 'content', ['label' => 'Perguntar nome', 'items' => [['id' => 'texto_nome', 'kind' => 'text', 'text' => 'Olá! Qual é seu nome?']]]),
                $node('coleta', 'wait_reply', ['label' => 'Guardar nome', 'field_ref' => 'nome', 'timeout_seconds' => 3600]),
                $node('sucesso', 'operations', ['label' => 'Identificar contato', 'actions' => [['id' => 'marcar', 'kind' => 'add_tag', 'tag_ref' => 'identificado']]]),
                $node('prazo', 'content', ['label' => 'Prazo encerrado', 'items' => [['id' => 'texto_prazo', 'kind' => 'text', 'text' => 'Quando precisar, envie uma nova mensagem.']]]),
            ], [$edge('inicio', 'next', 'pergunta'), $edge('pergunta', 'next', 'coleta'), $edge('coleta', 'replied', 'sucesso'), $edge('coleta', 'not_replied', 'prazo')], [['id' => 'identificado', 'name' => 'Contato identificado']], [['id' => 'nome', 'name' => 'Nome informado', 'type' => 'text']]),
            $envelope('Menu de atendimento', [
                $node('inicio', 'start', ['label' => 'Início']),
                $node('menu', 'menu', ['label' => 'Escolher assunto', ...FlowMenu::defaults(), 'question' => 'Como podemos ajudar?', 'options' => [['id' => 'vendas', 'label' => 'Vendas'], ['id' => 'suporte', 'label' => 'Suporte']]]),
                $node('vendas', 'content', ['label' => 'Catálogo pendente', 'items' => [['id' => 'catalogo', 'kind' => 'media', 'media_type' => 'document', 'original_name' => 'Catálogo de produtos.pdf', 'caption' => 'Conheça nossos produtos.']]]),
                $node('suporte', 'operations', ['label' => 'Abrir atendimento', 'actions' => [['id' => 'humano', 'kind' => 'open_human']]]),
            ], [$edge('inicio', 'next', 'menu'), $edge('menu', 'option:vendas', 'vendas'), $edge('menu', 'option:suporte', 'suporte')]),
        ];
    }

    public function markdown(): string
    {
        $text = <<<'MD'
# Guia CHAIT para criar fluxos com uma IA

Anexe este guia à IA de sua preferência e descreva objetivo, mensagens, dados a coletar e o que fazer em cada caminho. Peça apenas um JSON compatível com este guia. Copie o resultado ou salve-o como `.json` e use **Importar JSON** no editor do fluxo que deseja substituir.

A confirmação substitui e salva todos os blocos do rascunho e da prévia compartilhada, sem desfazer. O nome do fluxo aberto permanece. Publicar continua sendo uma ação separada. Revise mensagens, caminhos e configurações antes de publicar.

## Regras para gerar o arquivo

- Use `format: "chait-flow"` e `schema_version: "1.0"`. Um arquivo representa um fluxo completo com exatamente um bloco `start`.
- Use identificadores locais de texto únicos para blocos; itens, ações e opções também precisam de IDs locais. Não invente IDs de banco, credenciais ou URLs de arquivos.
- Declare etiquetas em `dependencies.tags` com `id`, `name` e `color` opcional. Declare campos em `dependencies.fields` com `id`, `name` e `type: "text"`.
- Use `tag_ref` e `field_ref` para apontar às dependências declaradas. O CHAIT reutiliza recursos de mesmo nome ou cria os ausentes após a confirmação. Nomes conflitantes precisam ser corrigidos.
- Campos padrão de Condição usam `field_key`: `display_name`, `email` ou `cpf`, sem dependência personalizada.
- Cada conexão contém `source`, `output` e `target`. Use somente as saídas documentadas abaixo. Uma saída aceita no máximo uma conexão; caminhos podem convergir. Ciclos e retornos são proibidos.
- Uma saída sem conexão encerra o ramo. Não crie um bloco de encerramento inexistente. Blocos desconectados do início não executam.
- Texto com pergunta não aguarda nem coleta resposta: adicione `wait_reply` para coletar ou use `menu` para escolhas.
- Arquivos são pendências: informe `media_type`, descrição em `original_name` e legenda opcional. O usuário anexa o arquivo depois; nenhum arquivo é transportado.
- Atendentes, outros fluxos e sequências precisam ser selecionados manualmente no editor. Preserve a operação, omitindo o destino.
- Abrir atendimento humano ou pausar automações encerra a execução e deve ser a última ação do bloco.
- Botão no Conteúdo não cria ramificações nem espera clique. Use Menu para escolhas com caminhos diferentes. Formatos interativos dependem da homologação da conexão antes de publicar.
- Limite: 5 MiB, 200 blocos e 500 conexões. Campos e listas têm os limites descritos no contrato abaixo.
- Configurações podem ficar pendentes, mas não invente propriedades, blocos ou ligações. Se a importação falhar, cole os erros copiados na IA e peça uma correção.
- Ao aperfeiçoar um arquivo exportado com um bloco IA já existente, preserve esse bloco, suas instruções, identificador e ligações. O importador o reconhece; este guia documenta somente a criação dos demais blocos e não ensina a criar ou configurar blocos IA.

## Blocos disponíveis para criação

MD;
        foreach (app(FlowCapabilities::class)->catalog()['nodes'] as $node) {
            if ($node['type'] === 'ai') {
                continue;
            }
            $usage = $node['type'] === 'start' ? 'Inclua exatamente um início, sem conexões de entrada.' : $node['usage'];
            $outputs = array_column($node['outputs'], 'id');
            if ($node['type'] === 'menu') {
                $outputs[] = 'option:{id da opção}';
            }
            $text .= "- **{$node['label']}** (`{$node['type']}`): {$usage} Saídas: ".($outputs ? implode(', ', $outputs) : 'nenhuma').".\n";
            foreach ($node['outputs'] as $output) {
                $text .= "  - `{$output['id']}`: {$output['meaning']}\n";
            }
        }
        $text .= "\n## Exemplos completos\n\n";
        foreach ($this->examples() as $example) {
            $text .= '### '.$example['name']."\n\n```json\n".json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```\n\n";
        }
        $schema = app(FlowTransportContract::class)->schema(false);
        foreach ($schema['properties']['nodes']['items']['oneOf'] as &$variant) {
            unset($variant['properties']['position']);
        }
        unset($variant);
        $text .= "## Contrato dos campos e limites\n\nEste JSON Schema documenta os blocos autorizados neste guia. A aplicação também verifica as conexões e os recursos declarados.\n\n```json\n".json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```\n";

        return $text;
    }
}
