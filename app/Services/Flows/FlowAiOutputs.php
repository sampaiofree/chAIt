<?php

namespace App\Services\Flows;

use Illuminate\Support\Str;

/** Converts editable graphs only; published versions remain immutable. */
class FlowAiOutputs
{
    public function convert(array $graph): array
    {
        $extra = [];
        foreach ($graph['nodes'] as &$node) {
            if ($node['type'] !== 'ai' || array_key_exists('ai_outputs', $node['data'])) {
                continue;
            }
            $node['data']['ai_outputs'] = FlowCapabilities::aiOutputDefaults();
            foreach ($graph['edges'] as $edge) {
                if ($edge['source'] === $node['id'] && $edge['sourceHandle'] === 'continue') {
                    $edge['id'] = (string) Str::uuid();
                    $edge['sourceHandle'] = 'ai_exit:finished';
                    $graph['edges'][] = $edge;
                }
            }
            $id = (string) Str::uuid();
            $extra[] = ['id' => $id, 'type' => 'operations', 'position' => ['x' => $node['position']['x'] + 320, 'y' => $node['position']['y'] + 240], 'data' => ['label' => 'Abrir atendimento humano', 'actions' => [['id' => (string) Str::uuid(), 'kind' => 'open_human', 'user_id' => null]]]];
            $graph['edges'][] = ['id' => (string) Str::uuid(), 'source' => $node['id'], 'sourceHandle' => 'ai_exit:human', 'target' => $id, 'targetHandle' => 'input'];
        }
        unset($node);
        $graph['nodes'] = [...$graph['nodes'], ...$extra];

        return app(FlowGraphValidator::class)->validate($graph);
    }
}
