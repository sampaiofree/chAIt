<?php

namespace App\Services\Flows;

use App\Models\Workspace;
use App\Rules\ContactTemplateRule;

class FlowCapabilities
{
    public static function aiDefaults(): array
    {
        return ['ai_prompt' => '', 'ai_model' => 'gpt-6.1-sol', 'ai_inactivity_seconds' => 3600, 'ai_expiry_action' => 'end', 'ai_max_messages' => null, 'ai_limit_action' => 'human', 'ai_media' => [], 'ai_tags' => [], 'ai_fields' => []];
    }

    public const FIELDS = [
        'start' => ['label', 'description'],
        'content' => ['label', 'description', 'items'],
        'menu' => ['label', 'description', 'menu_format', 'question', 'options', 'invalid_reply_text', 'max_invalid_replies', 'timeout_seconds', 'list_button_text'],
        'wait_reply' => ['label', 'description', 'field_id', 'timeout_seconds'],
        'condition' => ['label', 'description', 'checks', 'condition_operator'],
        'operations' => ['label', 'description', 'actions'],
        'jump_flow' => ['label', 'description', 'target_flow_id'],
        'ai' => ['label', 'description', 'ai_prompt', 'ai_model', 'ai_inactivity_seconds', 'ai_expiry_action', 'ai_max_messages', 'ai_limit_action', 'ai_media', 'ai_tags', 'ai_fields'],
    ];

    public const OUTPUTS = [
        'start' => ['next'], 'content' => ['next'], 'wait_reply' => ['replied', 'not_replied'],
        'menu' => ['continue'], 'operations' => ['next'], 'jump_flow' => [], 'condition' => ['yes', 'no'], 'ai' => ['continue'],
    ];

    public function outputsFor(array $node): array
    {
        $outputs = self::OUTPUTS[$node['type']] ?? [];
        if ($node['type'] === 'menu') {
            $outputs = [...array_map(fn ($option) => 'option:'.$option['id'], $node['data']['options'] ?? []), ...$outputs];
        }

        return $outputs;
    }

    public function rules(): array
    {
        $rules = [
            'schema_version' => ['required', 'integer', 'in:2'],
            'nodes' => ['required', 'array', 'min:1', 'max:200'], 'edges' => ['present', 'array', 'max:500'],
            'viewport' => ['required', 'array'], 'viewport.x' => ['required', 'numeric', 'between:-100000,100000'],
            'viewport.y' => ['required', 'numeric', 'between:-100000,100000'], 'viewport.zoom' => ['required', 'numeric', 'between:0.1,4'],
            'nodes.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'nodes.*.type' => ['required', 'string', 'in:start,content,wait_reply,operations,jump_flow,condition,ai,menu'],
            'nodes.*.position' => ['required', 'array'], 'nodes.*.position.x' => ['required', 'numeric', 'between:-100000,100000'],
            'nodes.*.position.y' => ['required', 'numeric', 'between:-100000,100000'], 'nodes.*.data' => ['required', 'array'],
            'nodes.*.data.label' => ['required', 'string', 'max:120'], 'nodes.*.data.description' => ['nullable', 'string', 'max:500'],
            'nodes.*.data.items' => ['sometimes', 'array', 'max:100'], 'nodes.*.data.items.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'nodes.*.data.items.*.kind' => ['required', 'in:text,media,delay,button'],
            'nodes.*.data.items.*.text' => ['nullable', 'string', 'max:4000'],
            'nodes.*.data.items.*.button' => ['nullable', 'array:kind,label,url,copy_text'],
            'nodes.*.data.items.*.button.kind' => ['nullable', 'in:url,reply,copy'],
            'nodes.*.data.items.*.button.label' => ['nullable', 'string', 'max:120', 'not_regex:/[|\p{Cc}]/u'],
            'nodes.*.data.items.*.button.url' => ['nullable', 'string', 'max:4000', 'url:http,https', 'not_regex:/[|\p{Cc}\s]/u'],
            'nodes.*.data.items.*.button.copy_text' => ['nullable', 'string', 'max:4000', 'not_regex:/[|\p{Cc}]/u'],
            'nodes.*.data.items.*.asset_id' => ['nullable', 'integer'], 'nodes.*.data.items.*.media_type' => ['nullable', 'in:image,video,audio,document'],
            'nodes.*.data.items.*.caption' => ['nullable', 'string', 'max:1024'], 'nodes.*.data.items.*.seconds' => ['nullable', 'integer', 'between:3,10'],
            'nodes.*.data.timeout_seconds' => ['nullable', 'integer', 'between:60,604800'],
            'nodes.*.data.menu_format' => ['nullable', 'in:numeric,button,list'],
            'nodes.*.data.question' => ['nullable', 'string', 'max:4000'],
            'nodes.*.data.options' => ['sometimes', 'array', 'list'],
            'nodes.*.data.options.*' => ['array:id,label'],
            'nodes.*.data.options.*.id' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'nodes.*.data.options.*.label' => ['nullable', 'string', 'max:120', 'not_regex:/[|\r\n]/'],
            'nodes.*.data.invalid_reply_text' => ['nullable', 'string', 'max:4000'],
            'nodes.*.data.max_invalid_replies' => ['nullable', 'integer', 'between:1,10'],
            'nodes.*.data.list_button_text' => ['nullable', 'string', 'max:120'],
            'nodes.*.data.condition_operator' => ['nullable', 'in:all,any'],
            'nodes.*.data.checks' => ['sometimes', 'array', 'list', 'max:50'],
            'nodes.*.data.checks.*' => ['array:kind,tag_id,field_id,field_key,operator,value'],
            'nodes.*.data.checks.*.kind' => ['nullable', 'in:tag,field'],
            'nodes.*.data.checks.*.tag_id' => ['nullable', 'integer', 'min:1'],
            'nodes.*.data.checks.*.field_id' => ['nullable', 'integer', 'min:1'],
            'nodes.*.data.checks.*.field_key' => ['nullable', 'in:display_name,email,cpf'],
            'nodes.*.data.checks.*.operator' => ['nullable', 'in:has,not_has,equals,contains'],
            'nodes.*.data.checks.*.value' => ['nullable', 'string', 'max:4000'], 'nodes.*.data.field_id' => ['nullable', 'integer'],
            'nodes.*.data.target_flow_id' => ['nullable', 'integer'], 'nodes.*.data.actions' => ['sometimes', 'array', 'max:20'],
            'nodes.*.data.ai_prompt' => ['nullable', 'string', 'max:8000'],
            'nodes.*.data.ai_model' => ['nullable', 'in:gpt-6-luna,gpt-6.1-sol,gpt-6-astra'],
            'nodes.*.data.ai_inactivity_seconds' => ['nullable', 'integer', 'between:300,604800'],
            'nodes.*.data.ai_expiry_action' => ['nullable', 'in:end,human'],
            'nodes.*.data.ai_max_messages' => ['nullable', 'integer', 'between:1,200'],
            'nodes.*.data.ai_limit_action' => ['nullable', 'in:continue,human'],
            'nodes.*.data.ai_media' => ['sometimes', 'array', 'max:20'],
            'nodes.*.data.ai_media.*.asset_id' => ['nullable', 'integer', 'min:1'],
            'nodes.*.data.ai_media.*.instruction' => ['nullable', 'string', 'max:2000'],
            'nodes.*.data.ai_tags' => ['sometimes', 'array', 'max:50'],
            'nodes.*.data.ai_tags.*.tag_id' => ['nullable', 'integer', 'min:1'],
            'nodes.*.data.ai_tags.*.instruction' => ['nullable', 'string', 'max:2000'],
            'nodes.*.data.ai_fields' => ['sometimes', 'array', 'max:50'],
            'nodes.*.data.ai_fields.*.field_id' => ['nullable', 'integer', 'min:1'],
            'nodes.*.data.ai_fields.*.instruction' => ['nullable', 'string', 'max:2000'],
            'nodes.*.data.ai_fields.*.allow_overwrite' => ['sometimes', 'boolean'],
            'nodes.*.data.actions.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'nodes.*.data.actions.*.kind' => ['required', 'in:add_tag,remove_tag,set_field,notify_member,open_human,pause_automation,add_to_sequence'],
            'nodes.*.data.actions.*.sequence_id' => ['nullable', 'integer'],
            'nodes.*.data.actions.*.tag_id' => ['nullable', 'integer'], 'nodes.*.data.actions.*.field_id' => ['nullable', 'integer'],
            'nodes.*.data.actions.*.value' => ['nullable', 'string', 'max:4000'], 'nodes.*.data.actions.*.user_id' => ['nullable', 'integer'],
            'nodes.*.data.actions.*.text' => ['nullable', 'string', 'max:4000'], 'nodes.*.data.actions.*.pause_mode' => ['nullable', 'in:timed,indefinite'],
            'nodes.*.data.actions.*.pause_seconds' => ['nullable', 'integer', 'between:60,604800'],
            'edges.*.id' => ['required', 'string', 'max:128', 'distinct'], 'edges.*.source' => ['required', 'string', 'max:64'],
            'edges.*.target' => ['required', 'string', 'max:64'], 'edges.*.sourceHandle' => ['required', 'string', 'max:64'],
            'edges.*.targetHandle' => ['required', 'string', 'in:input'],
        ];
        foreach (ContactTemplate::PATHS as $path => [$limit, $context]) {
            $rules[$path] = ['nullable', new ContactTemplateRule($limit, $context)];
        }

        return $rules;
    }

    public function catalog(?Workspace $workspace = null): array
    {
        $labels = ['start' => 'Início', 'content' => 'Conteúdo', 'wait_reply' => 'Aguardar Resposta', 'operations' => 'Operações', 'jump_flow' => 'Ir para Fluxo', 'condition' => 'Condição', 'ai' => 'IA', 'menu' => 'Menu'];
        $handles = ['next' => 'Próximo passo', 'replied' => 'Respondeu', 'not_replied' => 'Não respondeu', 'yes' => 'Sim', 'no' => 'Não', 'continue' => 'Continuar'];
        $usage = [
            'start' => 'Início único existente. A IA pode mover, mas não criar, remover ou reconfigurar. Não aceita entrada.',
            'content' => 'Executa textos, mídias, atrasos e no máximo um elemento Botão na ordem dos itens. Botão contém sua mensagem e uma ação URL, Resposta ou Copiar; nunca espera cliques e mantém somente next. Resposta entra como mensagem recebida, com o rótulo enviado. Use Menu para caminhos por escolha. Enviar uma pergunta não espera nem coleta resposta; conecte Aguardar Resposta para coletar. Mídias exigem seleção manual.',
            'wait_reply' => 'Espera texto não vazio e grava no campo personalizado do contato. Exige campo e prazo para publicar. Não interpreta intenção nem valida opções; use Condição após a coleta.',
            'condition' => 'Consulta etiquetas e campos de texto, sem coletar, enviar ou alterar dados. Combina checks com all/any. Para identificar opção não reconhecida, teste todas as alternativas antes do último Não.',
            'operations' => 'Executa ações em ordem. Abrir atendimento humano ou pausar encerra a execução e deve ser a última ação, sem continuação automática. Recursos de membros e sequências exigem seleção manual.',
            'jump_flow' => 'Transfere para outro fluxo publicado e ativo do workspace, sem retorno nem saída. Destino exige seleção manual; não usar para criar ciclos.',
            'ai' => 'Conversa com o contato durante a execução; é diferente do assistente construtor. Configure prompt, modelo, inatividade, ações de limites e recursos autorizados. Encaminhamento humano e encerramento são terminais. Não inserir quando o usuário pedir sem IA.',
            'menu' => 'Apresenta opções e espera seleção. Numérico aceita somente o número anunciado; Botões/Lista exigem seleção interativa. Respostas inválidas são tratadas internamente até o limite, sem ciclo no grafo. Não grava automaticamente a escolha em campo.',
        ];
        $meanings = [
            'next' => 'Executa o próximo bloco depois de concluir os passos; sem conexão, encerra o ramo.',
            'replied' => 'Recebeu texto não vazio e o gravou no campo. Não significa que corresponde às opções esperadas.',
            'not_replied' => 'O prazo venceu sem resposta aceita. Não grava resposta nem identifica uma opção inválida recebida.',
            'yes' => 'O critério configurado foi atendido.',
            'no' => 'O critério não foi atendido; pode testar outra alternativa. Só representa opção não reconhecida após excluir as demais alternativas.',
            'continue' => 'A IA concluiu pelo caminho Continuar ou atingiu limite configurado para continuar. Não é a saída de atendimento humano.',
        ];
        $graphRules = [
            'Proibidos ciclos, auto-conexões e retornos a blocos anteriores, mesmo quando o caminho contém espera por resposta.',
            'Reutilize o único Start existente; ele não aceita entrada. Use somente saídas reais do catálogo ou opções dinâmicas do Menu e entrada input.',
            'Cada saída aceita no máximo uma conexão. Caminhos podem convergir em um mesmo bloco se não formarem ciclo.',
            'Saída sem conexão encerra o ramo. Declare esse encerramento no plano; blocos sem caminho desde o Start não executam.',
            'Planeje os destinos de Sim e Não, Respondeu e Não respondeu, opções de Menu e Continuar, ou declare o encerramento intencional.',
            'Abrir atendimento humano ou pausar automações encerra a execução; não planeje continuação automática após essas ações.',
            'IDs de nós e arestas são gerados pelo servidor. IDs locais de itens, ações e opções são obrigatórios e estáveis; não são IDs de recursos do workspace.',
            'Lotes são atômicos: qualquer falha reverte o lote inteiro, preservando lotes anteriores. Crie pequenos trechos com blocos e conexões juntos.',
            'Estrutura válida não garante configuração completa ou conclusão do pedido. Confira caminhos, alcance desde o Start e pendências.',
        ];
        $result = [];
        foreach (self::OUTPUTS as $type => $outputs) {
            $result[] = ['type' => $type, 'label' => $labels[$type], 'usage' => $usage[$type], 'outputs' => array_map(fn ($id) => ['id' => $id, 'label' => $handles[$id], 'meaning' => match (true) {
                $type === 'menu' => 'Segue quando o prazo vence OU o limite de respostas inválidas é atingido; não representa uma escolha válida.',
                $type === 'operations' => 'Segue após ações não terminais. Abrir atendimento humano ou pausar encerra a execução e não utiliza esta saída.',
                default => $meanings[$id],
            }], $outputs), 'input' => $type !== 'start' ? 'input' : null, 'fields' => self::FIELDS[$type], 'dynamic_outputs' => $type === 'menu' ? ['field' => 'options', 'prefix' => 'option:', 'meaning' => 'option:{id} segue pela opção escolhida. Preserve o ID ao renomear ou reordenar; desconecte a saída no mesmo lote ao remover a opção.'] : null];
        }

        return ['contact_fields' => $workspace ? app(ContactTemplate::class)->catalog($workspace) : null, 'defaults' => ['ai' => self::aiDefaults()], 'content_button' => app(FlowContentButton::class)->catalog(), 'graph_rules' => $graphRules, 'menu' => ['defaults' => FlowMenu::defaults(), 'formats' => ['numeric', 'button', 'list'], 'matching' => 'Numérico aceita apenas o número anunciado. Botões/Lista exigem seleção interativa da espera vigente.'], 'schema_version' => 2, 'nodes' => $result, 'rules' => $this->rules(), 'standard_fields' => FlowCondition::STANDARD_FIELDS, 'condition' => ['max_checks' => 50, 'operators' => ['tag' => ['has', 'not_has'], 'field' => ['equals', 'contains']], 'matching' => 'Ignora maiúsculas, acentos e espaços nas extremidades. Contém procura substring. Campos vazios não correspondem.'], 'limits' => ['nodes' => 200, 'edges' => 500], 'limitations' => ['Condição consulta etiquetas e campos de texto do contato; não interpreta intenção ou histórico de mensagens.', 'Mídias, atendentes, outros fluxos e sequências precisam de seleção manual.', 'A saída human do bloco IA é terminal, sem conexão.']];
    }
}
