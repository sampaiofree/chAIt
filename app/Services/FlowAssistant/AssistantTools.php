<?php

namespace App\Services\FlowAssistant;

use App\Models\Flow;
use App\Models\FlowAiConversation;
use App\Models\FlowAiMessage;
use App\Models\FlowAiOperation;
use App\Models\FlowAiRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOpenAiIntegration;
use App\Rules\ContactTemplateRule;
use App\Services\Flows\ContactTemplate;
use App\Services\Flows\FlowCapabilities;
use App\Services\Flows\FlowCondition;
use App\Services\Flows\FlowContentButton;
use App\Services\Flows\FlowGraphValidator;
use App\Services\Flows\FlowMenu;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssistantTools
{
    public function __construct(private DraftSessionService $drafts, private AssistantAccess $access) {}

    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties === [] ? (object) [] : $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /** Derive configurable data schemas from the same Laravel rules as the editor. */
    public function dataSchema(): array
    {
        $tree = [];
        foreach (app(FlowCapabilities::class)->rules() as $path => $rules) {
            if (! str_starts_with($path, 'nodes.*.data.')) {
                continue;
            }
            $parts = explode('.', substr($path, strlen('nodes.*.data.')));
            $branch = &$tree;
            foreach ($parts as $part) {
                $branch = &$branch[$part];
            }
            $branch['_rules'] = $rules;
            unset($branch);
        }
        $build = function (array $branch, string $name = '', bool $isRoot = false) use (&$build): array {
            $rules = $branch['_rules'] ?? [];
            foreach ($rules as $rule) {
                if ($rule instanceof ContactTemplateRule) {
                    return $rule->schema();
                }
            }
            unset($branch['_rules']);
            if (isset($branch['*'])) {
                $schema = ['type' => 'array', 'items' => $build($branch['*'])];
                foreach ($rules as $rule) {
                    if (is_string($rule) && str_starts_with($rule, 'max:')) {
                        $schema['maxItems'] = (int) substr($rule, 4);
                    }
                }
            } elseif ($branch !== []) {
                $props = [];
                foreach ($branch as $key => $child) {
                    $schema = $build($child, $key);
                    if ($isRoot || ! in_array('required', $child['_rules'] ?? [], true)) {
                        $schema = ['anyOf' => [$schema, ['type' => 'null']]];
                    }
                    $props[$key] = $schema;
                }
                $schema = $this->object($props);
            } else {
                $schema = ['type' => in_array('integer', $rules, true) ? 'integer' : (in_array('boolean', $rules, true) ? 'boolean' : 'string')];
                foreach ($rules as $rule) {
                    if (str_starts_with($rule, 'in:')) {
                        $schema['enum'] = explode(',', substr($rule, 3));
                    }
                    if (str_starts_with($rule, 'max:') && $schema['type'] === 'string') {
                        $schema['maxLength'] = (int) substr($rule, 4);
                    }
                }
                if (in_array($name, ['asset_id', 'user_id', 'target_flow_id', 'sequence_id'], true)) {
                    return ['type' => 'null'];
                }
            }

            return $schema;
        };

        return $build($tree, '', true);
    }

    public function schemas(bool $canMutate): array
    {
        $function = fn ($name, $description, $parameters) => ['type' => 'function', 'name' => $name, 'description' => $description, 'strict' => true, 'parameters' => $parameters];
        $empty = $this->object([]);
        $tools = [];
        foreach (['get_editor_capabilities' => 'Consulte capacidades e limitações reais do editor.', 'get_current_flow' => 'Leia a prévia atual do fluxo aberto.', 'list_tags' => 'Consulte somente definições de etiquetas.', 'list_custom_fields' => 'Consulte somente definições de campos.', 'validate_flow' => 'Confira estrutura e pendências do fluxo sem publicar.'] as $name => $description) {
            $tools[] = $function($name, $description, $empty);
        }
        $tools[] = $function('propose_plan', 'Registre o briefing consolidado e um plano em linguagem simples para o usuário aprovar. Não construa neste turno.', $this->object(['briefing' => ['type' => 'string', 'maxLength' => 16000], 'plan' => ['type' => 'string', 'maxLength' => 16000]]));
        if ($canMutate) {
            $str = ['type' => 'string', 'maxLength' => 128];
            $pos = $this->object(['x' => ['type' => 'number'], 'y' => ['type' => 'number']]);
            $ops = [
                $this->object(['op' => ['type' => 'string', 'enum' => ['add_node']], 'client_ref' => $str, 'node_type' => ['type' => 'string', 'enum' => ['content', 'wait_reply', 'operations', 'jump_flow', 'condition', 'ai', 'menu']], 'data' => $this->dataSchema(), 'position' => $pos]),
                $this->object(['op' => ['type' => 'string', 'enum' => ['update_node']], 'node_ref' => $str, 'data' => $this->dataSchema(), 'clear_fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20]]),
                $this->object(['op' => ['type' => 'string', 'enum' => ['move_node']], 'node_ref' => $str, 'position' => $pos]),
                $this->object(['op' => ['type' => 'string', 'enum' => ['remove_node']], 'node_ref' => $str]),
                $this->object(['op' => ['type' => 'string', 'enum' => ['connect']], 'source_ref' => $str, 'source_handle' => $str, 'target_ref' => $str]),
                $this->object(['op' => ['type' => 'string', 'enum' => ['disconnect']], 'edge_id' => $str]),
            ];
            $tools[] = $function('apply_flow_patch', 'Aplique um lote na sessão temporária atual. IDs reais são gerados pelo servidor; reutilize referências retornadas.', $this->object(['expected_draft_revision' => ['type' => 'integer'], 'operations' => ['type' => 'array', 'minItems' => 1, 'maxItems' => config('flow-assistant.operations_per_batch'), 'items' => ['anyOf' => $ops]]]));
        }

        return $tools;
    }

    public function execute(Flow $flow, FlowAiRun $run, string $callId, string $name, array $args): array
    {
        return $this->drafts->locked($flow, function (Flow $flow) use ($run, $callId, $name, $args) {
            $run = FlowAiRun::findOrFail($run->id);
            $this->access->check(User::find($run->user_id), $flow, true);
            abort_unless(hash_equals($run->credential_fingerprint ?? '', hash('sha256', WorkspaceOpenAiIntegration::where('workspace_id', $flow->workspace_id)->first()?->api_key ?? '')), 409, 'A chave OpenAI mudou. Retome a execução.');
            $session = $this->drafts->current($flow);
            abort_unless($session && $session->generation === $run->generation && $session->base_revision === $flow->revision && $run->status === 'running', 409, 'A execução não está mais ativa.');
            abort_if($run->started_at && $run->started_at->addSeconds(config('flow-assistant.run_seconds'))->isPast(), 409, 'O tempo da execução terminou. Retome a prévia.');
            $fingerprint = hash('sha256', json_encode([$name, $args], JSON_THROW_ON_ERROR));
            $prior = FlowAiOperation::where('run_id', $run->id)->where('call_id', $callId)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->fingerprint, $fingerprint), 409, 'Uma chamada repetida tentou trocar de operação.');

                return $prior->result;
            }
            $result = match ($name) {
                'get_editor_capabilities' => app(FlowCapabilities::class)->catalog($flow->workspace),
                'get_current_flow' => ['revision' => $session->revision, 'name' => $session->name, 'graph' => $this->sanitize(app(FlowGraphValidator::class)->normalize($session->graph))],
                'list_tags' => $flow->workspace->tags()->orderBy('name')->get(['id', 'name'])->toArray(),
                'list_custom_fields' => $flow->workspace->contactCustomFields()->orderBy('name')->get(['id', 'name', 'type'])->toArray(),
                'validate_flow' => $this->validate($session->graph, $flow->workspace),
                'propose_plan' => $this->plan($run, $args),
                'apply_flow_patch' => $this->patch($flow, $run, $args),
                default => throw ValidationException::withMessages(['tool' => 'Ferramenta não autorizada.']),
            };
            $result = $this->sanitize($result);
            FlowAiOperation::create(['run_id' => $run->id, 'call_id' => $callId, 'name' => $name, 'fingerprint' => $fingerprint, 'result' => $result, 'draft_revision' => $this->drafts->current($flow)?->revision]);

            return $result;
        });
    }

    public function validate(array $graph, Workspace $workspace): array
    {
        $graph = app(FlowGraphValidator::class)->validate($graph);
        $pending = [];
        foreach ($graph['nodes'] as $node) {
            $d = $node['data'];
            if ($node['type'] === 'menu') {
                foreach (app(FlowMenu::class)->issues($d) as $path => $message) {
                    $pending[] = ['node_id' => $node['id'], 'path' => $path, 'message' => $message];
                }

                continue;
            }
            if ($node['type'] === 'condition') {
                $issues = app(FlowCondition::class)->issues($d, $workspace);
                foreach ($issues as $path => $message) {
                    $pending[] = ['node_id' => $node['id'], 'path' => $path, 'message' => $message];
                }

                continue;
            }
            if ($node['type'] === 'content') {
                foreach (app(FlowContentButton::class)->issues($d['items'] ?? []) as $path => $message) {
                    $pending[] = ['node_id' => $node['id'], 'path' => $path, 'message' => $message];
                }
            }
            $missing = match ($node['type']) {
                'content' => empty($d['items']) || collect($d['items'])->contains(fn ($i) => ($i['kind'] === 'text' && trim(ContactTemplate::display($i['text'] ?? '')) === '') || ($i['kind'] === 'media' && empty($i['asset_id']))),
                'wait_reply' => empty($d['field_id']) || empty($d['timeout_seconds']),
                'jump_flow' => empty($d['target_flow_id']),
                'ai' => trim(ContactTemplate::display($d['ai_prompt'] ?? '')) === '' || empty($d['ai_model']),
                'operations' => empty($d['actions']) || collect($d['actions'])->contains(fn ($a) => match ($a['kind']) {
                    'add_tag', 'remove_tag' => empty($a['tag_id']), 'set_field' => empty($a['field_id']), 'notify_member' => empty($a['user_id']), 'add_to_sequence' => empty($a['sequence_id']), default => false,
                }),
                default => false,
            };
            if ($missing) {
                $pending[] = ['node_id' => $node['id'], 'message' => 'Este bloco precisa de configuração manual antes de publicar.'];
            }
        }

        return ['structure_valid' => true, 'pending' => $pending, 'message' => 'A publicação manual executará a validação completa, incluindo referências e integração.'];
    }

    private function plan(FlowAiRun $run, array $args): array
    {
        Validator::make($args, ['briefing' => 'required|string|max:16000', 'plan' => 'required|string|max:16000'])->validate();
        $conversation = FlowAiConversation::findOrFail($run->conversation_id);
        FlowAiMessage::where('conversation_id', $conversation->id)->where('kind', 'plan')->where('status', 'pending')->update(['status' => 'superseded']);
        $message = FlowAiMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'kind' => 'plan', 'content' => $this->sanitize($args['plan']), 'status' => 'pending', 'generation' => $run->generation]);
        $conversation->update(['briefing' => $this->sanitize($args['briefing']), 'state' => 'awaiting_plan_approval', 'approved_plan_id' => null]);
        // A new proposed plan immediately removes mutation authority, even in an editing turn.
        $run->update(['purpose' => 'briefing']);

        return ['plan_id' => $message->id, 'awaiting_approval' => true];
    }

    public function patch(Flow $flow, FlowAiRun $run, array $args): array
    {
        $conversation = FlowAiConversation::findOrFail($run->conversation_id);
        abort_unless(in_array($run->purpose, ['building', 'editing']) && in_array($conversation->state, ['building', 'editing']) && $conversation->approved_plan_id !== null && FlowAiMessage::where('conversation_id', $conversation->id)->whereKey($conversation->approved_plan_id)->whereNotNull('approved_at')->whereIn('status', ['approved', 'saved', 'temporary'])->exists(), 403, 'Aprove o plano antes de construir.');
        Validator::make($args, ['expected_draft_revision' => 'required|integer', 'operations' => 'required|array|min:1|max:'.config('flow-assistant.operations_per_batch'), 'operations.*.op' => 'required|in:add_node,update_node,move_node,remove_node,connect,disconnect'])->validate();
        $session = $this->drafts->current($flow);
        abort_unless($session->revision === $args['expected_draft_revision'], 409, 'A revisão da prévia mudou. Leia o fluxo novamente.');
        $graph = app(FlowGraphValidator::class)->normalize($session->graph);
        $refs = [];
        foreach (FlowAiOperation::where('run_id', $run->id)->where('name', 'apply_flow_patch')->get() as $operation) {
            $refs = array_merge($refs, $operation->result['refs'] ?? []);
        }
        $resolve = function ($ref) use (&$refs) {
            return $refs[$ref] ?? $ref;
        };
        foreach ($args['operations'] as $op) {
            $allowed = match ($op['op']) {
                'add_node' => ['op', 'client_ref', 'node_type', 'data', 'position'], 'update_node' => ['op', 'node_ref', 'data', 'clear_fields'], 'move_node' => ['op', 'node_ref', 'position'], 'remove_node' => ['op', 'node_ref'], 'connect' => ['op', 'source_ref', 'source_handle', 'target_ref'], 'disconnect' => ['op', 'edge_id'],
            };
            if (array_diff(array_keys($op), $allowed) || array_diff(array_diff($allowed, ['clear_fields']), array_keys($op))) {
                throw ValidationException::withMessages(['operations' => 'Contrato de operação inválido.']);
            }
            if ($op['op'] === 'add_node') {
                abort_unless(is_string($op['client_ref']) && strlen($op['client_ref']) <= 128 && $op['client_ref'] !== '' && ! isset($refs[$op['client_ref']]) && ! collect($graph['nodes'])->contains('id', $op['client_ref']), 422, 'Referência de bloco inválida ou repetida.');
                abort_unless(isset(FlowCapabilities::FIELDS[$op['node_type']]) && $op['node_type'] !== 'start', 422, 'Tipo de bloco inválido.');
                $id = (string) Str::uuid();
                $data = $this->data($flow, $op['node_type'], $op['data']);
                $data += ['label' => collect(app(FlowCapabilities::class)->catalog()['nodes'])->firstWhere('type', $op['node_type'])['label'], 'description' => ''];
                $graph['nodes'][] = ['id' => $id, 'type' => $op['node_type'], 'data' => $data, 'position' => $op['position']];
                $refs[$op['client_ref']] = $id;
            } elseif ($op['op'] === 'connect') {
                $graph['edges'][] = ['id' => (string) Str::uuid(), 'source' => $resolve($op['source_ref']), 'sourceHandle' => $op['source_handle'], 'target' => $resolve($op['target_ref']), 'targetHandle' => 'input'];
            } elseif ($op['op'] === 'disconnect') {
                abort_unless(collect($graph['edges'])->contains('id', $op['edge_id']), 422, 'Conexão não encontrada.');
                $graph['edges'] = array_values(array_filter($graph['edges'], fn ($e) => $e['id'] !== $op['edge_id']));
            } else {
                $id = $resolve($op['node_ref']);
                $index = array_search($id, array_column($graph['nodes'], 'id'), true);
                abort_if($index === false, 422, 'Bloco não encontrado.');
                $node = &$graph['nodes'][$index];
                abort_if($node['type'] === 'start' && $op['op'] !== 'move_node', 422, 'O bloco Start não pode ser alterado pela IA.');
                if ($op['op'] === 'remove_node') {
                    unset($node);
                    array_splice($graph['nodes'], $index, 1);
                    $graph['edges'] = array_values(array_filter($graph['edges'], fn ($e) => $e['source'] !== $id && $e['target'] !== $id));
                } elseif ($op['op'] === 'move_node') {
                    $node['position'] = $op['position'];
                } else {
                    $node['data'] = array_replace($node['data'], $this->data($flow, $node['type'], $op['data']));
                    $clear = $op['clear_fields'] ?? [];
                    abort_unless(is_array($clear) && count($clear) <= 20 && ! array_diff($clear, array_diff(FlowCapabilities::FIELDS[$node['type']], ['label'])), 422, 'Campos inválidos para limpar.');
                    foreach ($clear as $field) {
                        unset($node['data'][$field]);
                    }
                }
                unset($node);
            }
        }
        $graph = app(FlowGraphValidator::class)->validate($graph);
        app(ContactTemplate::class)->references($graph, $flow->workspace, $session->graph);
        $session->update(['graph' => $graph, 'revision' => $session->revision + 1]);
        FlowAiMessage::create(['conversation_id' => $conversation->id, 'user_id' => $run->user_id, 'role' => 'system', 'kind' => 'operation', 'content' => count($args['operations']).' alterações aplicadas na prévia compartilhada.', 'status' => 'temporary', 'generation' => $run->generation]);

        return ['revision' => $session->revision, 'refs' => $refs, 'validation' => $this->validate($graph, $flow->workspace)];
    }

    private function data(Flow $flow, string $type, mixed $data): array
    {
        abort_unless(is_array($data), 422, 'Configuração inválida.');
        $data = array_filter($data, fn ($value) => $value !== null);
        abort_if(array_diff(array_keys($data), FlowCapabilities::FIELDS[$type]), 422, 'Configuração não suportada para este bloco.');
        $rules = app(FlowCapabilities::class)->rules();
        $walk = function (array $values, string $prefix = 'nodes.*.data') use (&$walk, $flow, $rules): array {
            $result = [];
            foreach ($values as $key => $value) {
                $path = $prefix.'.'.(is_int($key) ? '*' : $key);
                $known = isset($rules[$path]) || collect(array_keys($rules))->contains(fn ($rule) => str_starts_with($rule, $path.'.'));
                abort_unless($known, 422, 'Campo de configuração desconhecido.');
                if ($value === null) {
                    continue;
                }
                abort_if(in_array($key, ['asset_id', 'user_id', 'target_flow_id', 'sequence_id']), 422, 'Este recurso precisa ser selecionado manualmente.');
                if (in_array($key, ['tag_id', 'field_id'])) {
                    $query = $key === 'tag_id' ? $flow->workspace->tags() : $flow->workspace->contactCustomFields();
                    abort_unless(is_int($value) && $query->whereKey($value)->exists(), 422, 'Recurso inexistente neste workspace.');
                }
                if (isset(ContactTemplate::PATHS[$path]) && is_array($value)) {
                    $result[$key] = $value;
                } else {
                    $result[$key] = is_array($value) ? $walk($value, $path) : $value;
                }
            }

            return $result;
        };

        $result = $walk($data);
        if ($type === 'condition') {
            // Keep incomplete drafts, but reject populated IDs/keys that this assistant cannot select.
            foreach ($result['checks'] ?? [] as $check) {
                if (isset($check['field_key'])) {
                    abort_unless(in_array($check['field_key'], array_column(FlowCondition::STANDARD_FIELDS, 'key'), true), 422, 'Campo padrão inválido.');
                }
                if (isset($check['field_id'])) {
                    abort_unless($flow->workspace->contactCustomFields()->where('type', 'text')->whereKey($check['field_id'])->exists(), 422, 'Selecione um campo de texto deste workspace.');
                }
            }
        }

        return $result;
    }

    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (in_array($key, ['api_key', 'token', 'password', 'secret', 'authorization', 'phone', 'email', 'cpf'], true)) {
                    continue;
                }
                $out[$key] = in_array($key, ['asset_id', 'user_id', 'target_flow_id', 'sequence_id', 'original_name'], true) ? null : $this->sanitize($item);
            }

            return $out;
        }
        if (is_string($value)) {
            $value = preg_replace('/\bsk-[A-Za-z0-9_-]+\b/', '[credencial removida]', $value);
            $value = preg_replace('/\bBearer\s+[A-Za-z0-9._-]+/i', '[credencial removida]', $value);
            $value = preg_replace('/((?:api[_-]?key|token|password|secret|authorization)\s*[:=]\s*)[^\s,;]+/i', '$1[removido]', $value);
        }

        return $value;
    }
}
