<?php

namespace App\Services\Flows;

use App\Models\Flow;
use App\Models\FlowAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ContactTemplateRule;
use App\Services\FlowAssistant\AssistantAccess;
use App\Services\FlowAssistant\DraftSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class FlowTransport
{
    public function __construct(private FlowTransportContract $contract, private FlowGraphValidator $validator, private DraftSessionService $drafts) {}

    public function decode(string $json): array
    {
        if (strlen($json) > FlowTransportContract::MAX_BYTES) {
            $this->invalid('json', 'O arquivo deve ter no máximo 5 MiB.');
        }
        try {
            $object = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            if (! $object instanceof \stdClass) {
                $this->invalid('json', 'Informe um objeto JSON de fluxo CHAIT.');
            }
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid('json', 'JSON malformado ou acima da profundidade máxima de 64 níveis.');
        }
        Validator::make($document, [
            'format' => ['required', 'in:chait-flow'], 'schema_version' => ['required', 'string', 'in:1.0'],
            'name' => ['present', 'string', 'max:120'],
            'dependencies' => ['required', 'array:tags,fields'],
            'dependencies.tags' => ['present', 'array', 'list', 'max:1000'], 'dependencies.fields' => ['present', 'array', 'list', 'max:1000'],
            'dependencies.tags.*' => ['array:id,name,color'], 'dependencies.fields.*' => ['array:id,name,type'],
            'dependencies.tags.*.id' => ['required', 'string', 'max:64', 'distinct:strict'], 'dependencies.fields.*.id' => ['required', 'string', 'max:64', 'distinct:strict'],
            'dependencies.tags.*.name' => ['required', 'string', 'max:100'], 'dependencies.fields.*.name' => ['required', 'string', 'max:100'],
            'dependencies.tags.*.color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'], 'dependencies.fields.*.type' => ['required', 'in:text'],
            'nodes' => ['required', 'array', 'list', 'min:1', 'max:200'], 'nodes.*' => ['array:id,type,data,position'],
            'nodes.*.id' => ['required', 'string', 'max:64', 'distinct:strict'],
            'nodes.*.type' => ['required', 'in:'.implode(',', array_keys(FlowCapabilities::FIELDS))],
            'nodes.*.data' => ['required', 'array'],
            'edges' => ['present', 'array', 'list', 'max:500'], 'edges.*' => ['array:source,output,target'],
            'edges.*.source' => ['required', 'string', 'max:64'], 'edges.*.output' => ['required', 'string', 'max:64'], 'edges.*.target' => ['required', 'string', 'max:64'],
        ], FlowTransportContract::messages())->validate();
        if (array_diff(array_keys($document), ['format', 'schema_version', 'name', 'dependencies', 'nodes', 'edges'])) {
            $this->invalid('json', 'O arquivo contém propriedades desconhecidas ou dados internos não permitidos.');
        }
        foreach ($document['nodes'] as $index => $node) {
            $this->contract->validateData($node['type'], $node['data'], 'nodes.'.$index.'.data');
        }
        foreach (['tags', 'fields'] as $kind) {
            $names = [];
            foreach ($document['dependencies'][$kind] as $index => &$resource) {
                $resource['name'] = trim($resource['name']);
                if ($resource['name'] === '' || in_array($resource['name'], $names, true)) {
                    $this->invalid('dependencies.'.$kind.'.'.$index.'.name', 'O nome deve ser preenchido e não pode declarar recursos equivalentes mais de uma vez.');
                }
                $names[] = $resource['name'];
            }
            unset($resource);
        }

        return $document;
    }

    /** Resolve without creating anything during validation; the confirmed import repeats this under lock. */
    private function resources(array $document, Workspace $workspace, ?User $actor): array
    {
        $resolved = ['tags' => [], 'fields' => []];
        foreach (['tags', 'fields'] as $kind) {
            foreach ($document['dependencies'][$kind] as $index => $resource) {
                $query = $kind === 'tags' ? $workspace->tags() : $workspace->contactCustomFields();
                $matches = $query->where('name', $resource['name'])->get();
                if ($matches->count() > 1 || ($kind === 'fields' && $matches->first() && $matches->first()->type !== $resource['type'])) {
                    $this->invalid('dependencies.'.$kind.'.'.$index, 'Recurso existente incompatível ou ambíguo. Corrija o nome ou o tipo antes de importar.');
                }
                $existing = $matches->first();
                if (! $existing && $actor) {
                    $existing = $query->firstOrCreate(['name' => $resource['name']], $kind === 'tags'
                        ? ['color' => $resource['color'] ?? '#16a34a', 'created_by_user_id' => $actor->id]
                        : ['type' => 'text']);
                }
                $resolved[$kind][$resource['id']] = $actor ? $existing->id : $index + 1;
            }
        }

        return $resolved;
    }

    private function internalData(array $data, array $resources, string $path): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, FlowTransportContract::RESOURCE_KEYS, true)) {
                $kind = $key === 'tag_ref' ? 'tags' : 'fields';
                $internal = $key === 'tag_ref' ? 'tag_id' : 'field_id';
                if ($value !== null && ! array_key_exists($value, $resources[$kind])) {
                    $this->invalid($path.'.'.$key, 'A referência deve estar declarada em dependencies.'.$kind.'.');
                }
                unset($data[$key]);
                $data[$internal] = $value === null ? null : $resources[$kind][$value];
            } elseif (is_array($value)) {
                $data[$key] = $this->internalData($value, $resources, $path.'.'.$key);
            }
        }

        return $data;
    }

    public function graph(array $document, Workspace $workspace, ?User $actor = null): array
    {
        $resources = $this->resources($document, $workspace, $actor);
        $ids = [];
        $nodes = [];
        foreach ($document['nodes'] as $index => $node) {
            $ids[$node['id']] = (string) Str::uuid();
            $data = $this->internalData($node['data'], $resources, 'nodes.'.$index.'.data');
            if ($node['type'] === 'ai') {
                $defaults = FlowCapabilities::aiDefaults();
                $data += $defaults;
                foreach (['ai_model', 'ai_expiry_action', 'ai_limit_action'] as $field) {
                    $data[$field] = $defaults[$field];
                }
                foreach ($data['ai_fields'] as &$field) {
                    $field['allow_overwrite'] = false;
                }
                unset($field);
            }
            $nodes[] = ['id' => $ids[$node['id']], 'type' => $node['type'], 'data' => $data, 'position' => ['x' => 0, 'y' => 0]];
        }
        $edges = [];
        foreach ($document['edges'] as $index => $edge) {
            if (! isset($ids[$edge['source']], $ids[$edge['target']])) {
                $this->invalid('edges.'.$index, 'A conexão aponta para um bloco inexistente.');
            }
            $source = $document['nodes'][array_search($edge['source'], array_column($document['nodes'], 'id'), true)];
            if (! in_array($edge['output'], app(FlowCapabilities::class)->outputsFor($source), true)) {
                $this->invalid('edges.'.$index.'.output', 'A saída não existe no bloco '.$edge['source'].'.');
            }
            $edges[] = ['id' => (string) Str::uuid(), 'source' => $ids[$edge['source']], 'target' => $ids[$edge['target']], 'sourceHandle' => $edge['output'], 'targetHandle' => 'input'];
        }
        try {
            $graph = $this->validator->validate(['schema_version' => 2, 'nodes' => $nodes, 'edges' => $edges, 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]]);
        } catch (ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $path => $messages) {
                $path = str_replace('graph.', '', $path);
                if ($path === 'edges' && ($index = $this->problemEdge($document)) !== null) {
                    $edge = $document['edges'][$index];
                    $path = 'edges.'.$index;
                    $messages = array_map(fn ($message) => $message.' ('.$edge['source'].' → '.$edge['target'].')', $messages);
                }
                foreach ($ids as $portable => $internal) {
                    $path = str_replace($internal, $portable, $path);
                }
                $errors[$path] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $positions = array_column($document['nodes'], 'position');
        $usable = count($positions) === count($nodes) && collect($positions)->every(fn ($position) => is_array($position)
            && is_numeric($position['x'] ?? null) && is_numeric($position['y'] ?? null)
            && abs((float) $position['x']) <= 100000 && abs((float) $position['y']) <= 100000);
        if ($usable) {
            foreach ($graph['nodes'] as $index => &$node) {
                $node['position'] = ['x' => (float) $positions[$index]['x'], 'y' => (float) $positions[$index]['y']];
            }
            unset($node);
        } else {
            $graph['nodes'] = $this->layout($graph);
        }

        return $this->validator->validate($graph);
    }

    /** Locate an edge only after the shared graph validator has rejected its structure. */
    private function problemEdge(array $document): ?int
    {
        $nodes = array_column($document['nodes'], null, 'id');
        $adjacency = [];
        $used = [];
        foreach ($document['edges'] as $index => $edge) {
            $key = $edge['source'].':'.$edge['output'];
            if ($edge['source'] === $edge['target'] || $nodes[$edge['target']]['type'] === 'start' || isset($used[$key])) {
                return $index;
            }
            $used[$key] = true;
            $adjacency[$edge['source']][] = $edge['target'];
        }
        foreach ($document['edges'] as $index => $edge) {
            $pending = [$edge['target']];
            $visited = [];
            while ($pending !== []) {
                $id = array_pop($pending);
                if ($id === $edge['source']) {
                    return $index;
                }
                if (! isset($visited[$id])) {
                    $visited[$id] = true;
                    array_push($pending, ...($adjacency[$id] ?? []));
                }
            }
        }

        return null;
    }

    private function layout(array $graph): array
    {
        $levels = [];
        $incoming = array_fill_keys(array_column($graph['nodes'], 'id'), 0);
        $targets = [];
        foreach ($graph['edges'] as $edge) {
            $incoming[$edge['target']]++;
            $targets[$edge['source']][] = $edge['target'];
        }
        $queue = array_keys(array_filter($incoming, fn ($count) => $count === 0));
        foreach ($queue as $id) {
            $levels[$id] = 0;
        }
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($targets[$id] ?? [] as $target) {
                $levels[$target] = max($levels[$target] ?? 0, $levels[$id] + 1);
                if (--$incoming[$target] === 0) {
                    $queue[] = $target;
                }
            }
        }
        $slots = [];
        $nodes = $graph['nodes'];
        $rowHeight = max(420, ...array_map(fn ($node) => $node['type'] === 'menu' ? 200 + count($node['data']['options'] ?? []) * 50 : 420, $nodes));
        $rowHeight = min($rowHeight, (int) floor(99000 / max(1, ...array_values($levels))));
        foreach ($nodes as &$node) {
            $level = $levels[$node['id']];
            $slot = $slots[$level] ?? 0;
            $node['position'] = ['x' => 80 + $slot * 320, 'y' => 80 + $level * $rowHeight];
            $slots[$level] = $slot + 1;
        }
        unset($node);

        return $nodes;
    }

    public function validate(Flow $flow, User $actor, array $expected, string $json): void
    {
        $document = $this->decode($json);
        $this->drafts->locked($flow, function (Flow $locked) use ($actor, $expected, $document) {
            app(AssistantAccess::class)->check($actor, $locked);
            $this->drafts->check($locked, $expected);
            $this->graph($document, $locked->workspace);
        });
    }

    public function import(Flow $flow, User $actor, array $expected, string $json): Flow
    {
        $document = $this->decode($json);

        return DB::transaction(function () use ($flow, $actor, $expected, $document) {
            Workspace::whereKey($flow->workspace_id)->lockForUpdate()->firstOrFail();

            return $this->drafts->locked($flow, function (Flow $locked) use ($actor, $expected, $document) {
                app(AssistantAccess::class)->check($actor, $locked);
                $this->drafts->check($locked, $expected);
                $graph = $this->graph($document, $locked->workspace, $actor);

                return $this->drafts->replaceForImport($locked, $expected, $graph, $actor->id);
            });
        }, 3);
    }

    public function export(Flow $flow, string $source): array
    {
        $graph = $source === 'published' ? $flow->publishedVersion?->graph : $flow->graph;
        abort_unless($graph, 404, 'Este fluxo ainda não tem versão publicada.');
        $graph = $this->validator->normalize($graph);
        $dependencies = ['tags' => [], 'fields' => []];
        $maps = ['tags' => [], 'fields' => []];
        $workspace = $flow->workspace;
        $tags = $workspace->tags()->get()->keyBy('id');
        $fields = $workspace->contactCustomFields()->get()->keyBy('id');
        $assets = FlowAsset::where('workspace_id', $flow->workspace_id)->where('flow_id', $flow->id)->get()->keyBy('id');
        $convert = function (array $data) use (&$convert, &$dependencies, &$maps, $tags, $fields, $assets): array {
            if (isset($data['asset_id'])) {
                $asset = $assets->get($data['asset_id']);
                if ($asset) {
                    $data['original_name'] = $asset->original_name;
                    $data['media_type'] = FlowMedia::type($asset->mime_type);
                }
            }
            foreach ($data as $key => $value) {
                if (isset(FlowTransportContract::RESOURCE_KEYS[$key])) {
                    unset($data[$key]);
                    $kind = $key === 'tag_id' ? 'tags' : 'fields';
                    $resource = ($kind === 'tags' ? $tags : $fields)->get($value);
                    if ($resource && $kind === 'fields' && $resource->type !== 'text') {
                        $this->invalid('export', 'O fluxo referencia um campo de tipo não suportado pelo formato 1.0.');
                    }
                    $ref = null;
                    if ($resource) {
                        if (! isset($maps[$kind][$value])) {
                            $maps[$kind][$value] = ($kind === 'tags' ? 'tag_' : 'field_').(count($maps[$kind]) + 1);
                            $dependencies[$kind][] = ['id' => $maps[$kind][$value], 'name' => $resource->name] + ($kind === 'tags' ? ['color' => $resource->color] : ['type' => 'text']);
                        }
                        $ref = $maps[$kind][$value];
                    }
                    $data[FlowTransportContract::RESOURCE_KEYS[$key]] = $ref;
                } elseif (in_array($key, FlowTransportContract::PRIVATE_KEYS, true)) {
                    unset($data[$key]);
                } elseif (is_array($value)) {
                    $data[$key] = $convert($value);
                }
            }

            return $data;
        };
        $ids = [];
        $nodes = [];
        foreach ($graph['nodes'] as $index => $node) {
            $ids[$node['id']] = 'node_'.($index + 1);
            $data = $convert(array_intersect_key($node['data'], array_flip(FlowCapabilities::FIELDS[$node['type']])));
            $data = $this->filterData($data, $this->contract->dataRules($node['type']));
            $nodes[] = ['id' => $ids[$node['id']], 'type' => $node['type'], 'data' => $data, 'position' => $node['position']];
        }
        $edges = array_map(fn ($edge) => ['source' => $ids[$edge['source']], 'output' => $edge['sourceHandle'], 'target' => $ids[$edge['target']]], $graph['edges']);

        return ['format' => 'chait-flow', 'schema_version' => '1.0', 'name' => $flow->name, 'dependencies' => $dependencies, 'nodes' => $nodes, 'edges' => $edges];
    }

    private function filterData(array $value, array $rules, string $prefix = ''): array
    {
        foreach ($rules[$prefix] ?? [] as $rule) {
            if ($rule instanceof ContactTemplateRule) {
                return $value;
            }
        }
        $filtered = [];
        foreach ($value as $key => $item) {
            $path = ltrim($prefix.'.'.$key, '.');
            if (! array_key_exists($path, $rules) && ! ctype_digit((string) $key)) {
                continue;
            }
            if (is_array($item)) {
                $filtered[$key] = $this->filterData($item, $rules, ctype_digit((string) $key) ? $prefix.'.*' : $path);
            } else {
                $filtered[$key] = $item;
            }
        }

        return $filtered;
    }

    private function invalid(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
