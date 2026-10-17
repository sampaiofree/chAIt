<?php

namespace App\Services\Flows;

use App\Models\Flow;
use App\Models\FlowAsset;
use App\Models\Sequence;
use App\Models\Workspace;
use App\Models\WorkspaceOpenAiIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FlowGraphValidator
{
    public function normalize(array $graph): array
    {
        if (! is_array($graph['nodes'] ?? [])) {
            return $graph;
        }
        // Write to the actual node array; iterating a ?? expression by reference copies it.
        foreach (array_keys($graph['nodes'] ?? []) as $index) {
            $node = &$graph['nodes'][$index];
            if (! is_array($node)) {
                unset($node);

                continue;
            }
            if (($graph['schema_version'] ?? null) === 1 && ($node['type'] ?? null) === 'content' && ! isset($node['data']['items'])) {
                $node['data']['items'] = array_map(fn (array $message) => [
                    'id' => $message['id'], 'kind' => 'text', 'text' => $message['text'] ?? '',
                ], $node['data']['messages'] ?? []);
                unset($node['data']['messages']);
            }
            if (($node['type'] ?? null) === 'condition' && is_array($node['data'] ?? null)) {
                $node['data'] = app(FlowCondition::class)->normalize($node['data']);
            }
            unset($node);
        }
        if (($graph['schema_version'] ?? null) === 1) {
            $graph['schema_version'] = 2;
        }

        return $graph;
    }

    public function validate(array $graph): array
    {
        $graph = $this->normalize($graph);
        Validator::make($graph, app(FlowCapabilities::class)->rules())->validate();

        foreach ($graph['nodes'] as $node) {
            if ($node['type'] !== 'ai' && is_array($node['data']['ai_prompt'] ?? null)) {
                $this->invalid('graph.nodes.'.$node['id'].'.ai_prompt', 'Somente o prompt do bloco IA aceita estes chips.');
            }
            if ($node['type'] === 'content') {
                foreach ($node['data']['items'] ?? [] as $item) {
                    if (($item['kind'] ?? '') === 'button' && ($item['button']['kind'] ?? '') === 'reply') {
                        foreach ([$item['text'] ?? '', ...array_values($item['button'])] as $value) {
                            if (is_array($value)) {
                                $this->invalid('graph.nodes.'.$node['id'].'.items', 'O elemento Resposta não aceita chips. Remova os campos antes de trocar o tipo.');
                            }
                        }
                    }
                }
            }
            if ($node['type'] !== 'content' && isset($node['data']['items'])) {
                foreach ($node['data']['items'] as $item) {
                    foreach ([$item['text'] ?? '', $item['caption'] ?? '', ...array_values($item['button'] ?? [])] as $value) {
                        if (is_array($value)) {
                            $this->invalid('graph.nodes.'.$node['id'], 'Este bloco não aceita chips de conteúdo.');
                        }
                    }
                }
            }
            if ($node['type'] === 'content' && collect($node['data']['items'] ?? [])->where('kind', 'button')->count() > 1) {
                $this->invalid('graph.nodes.'.$node['id'].'.items', 'O bloco Conteúdo permite somente um elemento Botão.');
            }
            if ($node['type'] === 'menu') {
                $ids = array_column($node['data']['options'] ?? [], 'id');
                if (count($ids) !== count(array_unique($ids))) {
                    $this->invalid('graph.nodes.'.$node['id'].'.options', 'Os IDs das opções devem ser únicos no Menu.');
                }
            }
            foreach (['ai_media' => 'asset_id', 'ai_tags' => 'tag_id', 'ai_fields' => 'field_id'] as $list => $resourceKey) {
                $ids = array_filter(array_column($node['data'][$list] ?? [], $resourceKey), fn ($id) => $id !== null);
                if (count($ids) !== count(array_unique($ids))) {
                    $this->invalid('graph.nodes.'.$node['id'].'.'.$list, 'Cada recurso pode ser autorizado apenas uma vez no bloco.');
                }
            }
        }

        $nodes = collect($graph['nodes'])->keyBy('id');
        if ($nodes->count() !== count($graph['nodes'])) {
            $this->invalid('graph.nodes', 'Os IDs dos blocos devem ser únicos.');
        }
        if ($nodes->where('type', 'start')->count() !== 1) {
            $this->invalid('graph.nodes', 'O fluxo deve ter exatamente um bloco Start.');
        }
        $used = [];
        $adjacency = [];
        foreach ($graph['edges'] as $edge) {
            $source = $nodes->get($edge['source']);
            $target = $nodes->get($edge['target']);
            if (! $source || ! $target || $source['id'] === $target['id']) {
                $this->invalid('graph.edges', 'A conexão aponta para um bloco inválido.');
            }
            if ($target['type'] === 'start') {
                $this->invalid('graph.edges', 'O bloco Start não aceita conexões de entrada.');
            }
            if (! in_array($edge['sourceHandle'], app(FlowCapabilities::class)->outputsFor($source), true)) {
                $this->invalid('graph.edges', 'A saída selecionada não existe neste bloco.');
            }
            $key = $source['id'].':'.$edge['sourceHandle'];
            if (isset($used[$key])) {
                $this->invalid('graph.edges', 'Cada saída pode ter apenas uma conexão.');
            }
            $used[$key] = true;
            $adjacency[$source['id']][] = $target['id'];
        }
        $visiting = [];
        $visited = [];
        $visit = function (string $id) use (&$visit, &$visiting, &$visited, $adjacency): void {
            if (isset($visiting[$id])) {
                $this->invalid('graph.edges', 'As conexões não podem formar ciclos.');
            }
            if (isset($visited[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach ($adjacency[$id] ?? [] as $next) {
                $visit($next);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
        };
        foreach ($nodes->keys() as $id) {
            $visit((string) $id);
        }

        foreach ($graph['nodes'] as &$node) {
            if ($node['type'] === 'ai' && isset($node['data']['ai_prompt'])) {
                $node['data']['ai_prompt'] = ContactTemplate::normalize($node['data']['ai_prompt']);
            }
            if ($node['type'] === 'content' && isset($node['data']['items'])) {
                foreach ($node['data']['items'] as &$item) {
                    foreach (['text', 'caption'] as $key) {
                        if (isset($item[$key])) {
                            $item[$key] = ContactTemplate::normalize($item[$key]);
                        }
                    }
                    foreach (['label', 'url', 'copy_text'] as $key) {
                        if (isset($item['button'][$key])) {
                            $item['button'][$key] = ContactTemplate::normalize($item['button'][$key]);
                        }
                    }
                }
            }
            unset($item);
        }
        unset($node);

        return $graph;
    }

    /** Recheck local references after acquiring the publication/deletion workspace lock. */
    public function validateResourceReferences(array $graph, Workspace $workspace): void
    {
        app(ContactTemplate::class)->references($graph, $workspace, $graph);
        $tags = $workspace->tags()->pluck('id')->all();
        $fields = $workspace->contactCustomFields()->pluck('id')->all();
        $textFields = $workspace->contactCustomFields()->where('type', 'text')->pluck('id')->all();
        $reachable = $this->reachableIds($graph);
        foreach ($graph['nodes'] as $node) {
            if (! isset($reachable[$node['id']])) {
                continue;
            }
            $data = $node['data'];
            $path = 'graph.nodes.'.$node['id'];
            if ($node['type'] === 'condition') {
                foreach (app(FlowCondition::class)->issues($data, $workspace) as $key => $message) {
                    $this->invalid($path.'.'.$key, $message);
                }
            }
            $references = [];
            if ($node['type'] === 'wait_reply') {
                $references[] = ['field_id' => $data['field_id'] ?? null];
            }
            if ($node['type'] === 'operations') {
                $references = $data['actions'] ?? [];
            }
            if ($node['type'] === 'ai') {
                $references = array_merge($data['ai_tags'] ?? [], $data['ai_fields'] ?? []);
            }
            foreach ($references as $reference) {
                if (isset($reference['tag_id']) && ! in_array($reference['tag_id'], $tags, true)) {
                    $this->invalid($path, 'Uma etiqueta foi excluída. Selecione uma etiqueta deste workspace.');
                }
                $allowed = $node['type'] === 'ai' ? $textFields : $fields;
                if (isset($reference['field_id']) && ! in_array($reference['field_id'], $allowed, true)) {
                    $this->invalid($path, 'Um campo está indisponível. Selecione um campo deste workspace.');
                }
            }
        }
    }

    private function reachableIds(array $graph): array
    {
        $start = collect($graph['nodes'])->firstWhere('type', 'start');
        $edges = collect($graph['edges'])->groupBy('source');
        $reachable = [];
        $pending = [$start['id']];
        while ($pending) {
            $id = array_pop($pending);
            if (isset($reachable[$id])) {
                continue;
            }
            $reachable[$id] = true;
            foreach ($edges->get($id, []) as $edge) {
                $pending[] = $edge['target'];
            }
        }

        return $reachable;
    }

    public function validateForPublish(array $graph, Workspace $workspace, Flow $flow): array
    {
        $graph = $this->validate($graph);
        app(ContactTemplate::class)->references($graph, $workspace, $graph);
        $nodes = collect($graph['nodes'])->keyBy('id');
        $edgesBySource = collect($graph['edges'])->groupBy('source');
        $start = $nodes->firstWhere('type', 'start');
        $reachable = [];
        $pending = [$start['id']];
        while ($pending) {
            $id = array_pop($pending);
            if (isset($reachable[$id])) {
                continue;
            }
            $reachable[$id] = true;
            foreach ($edgesBySource->get($id, []) as $edge) {
                $pending[] = $edge['target'];
            }
        }
        $reachableNodes = collect($graph['nodes'])->filter(fn ($node) => isset($reachable[$node['id']]));
        if ($reachableNodes->count() < 2) {
            $this->invalid('graph.nodes', 'Conecte e configure ao menos um bloco depois de Start.');
        }
        $tags = $workspace->tags()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $fields = $workspace->contactCustomFields()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $members = $workspace->memberships()->whereNotNull('joined_at')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $users = $workspace->memberships()->whereNotNull('joined_at')->whereHas('user', fn ($query) => $query->whereNotNull('phone'))->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $assets = FlowAsset::query()->where('workspace_id', $workspace->id)->where('flow_id', $flow->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($reachableNodes as $node) {
            $data = $node['data'];
            $id = $node['id'];
            if ($node['type'] === 'content') {
                $items = $data['items'] ?? [];
                if ($items === []) {
                    $this->invalid("graph.nodes.{$id}", 'Adicione ao menos um texto ou arquivo ao bloco Conteúdo.');
                }
                foreach (app(FlowContentButton::class)->issues($items) as $path => $message) {
                    $this->invalid("graph.nodes.{$id}.{$path}", $message);
                }
                foreach ($items as $item) {
                    if ($item['kind'] === 'text' && trim(ContactTemplate::display($item['text'] ?? '')) === '') {
                        $this->invalid("graph.nodes.{$id}", 'Preencha os textos do bloco Conteúdo.');
                    }
                    if ($item['kind'] === 'media' && (! in_array((int) ($item['asset_id'] ?? 0), $assets, true) || empty($item['media_type']))) {
                        $this->invalid("graph.nodes.{$id}", 'Selecione um arquivo válido para cada item de mídia.');
                    }
                }
            }
            if ($node['type'] === 'menu') {
                foreach (app(FlowMenu::class)->issues($data) as $path => $message) {
                    $this->invalid("graph.nodes.{$id}.{$path}", $message);
                }
            }
            if ($node['type'] === 'wait_reply' && (! in_array((int) ($data['field_id'] ?? 0), $fields, true) || empty($data['timeout_seconds']))) {
                $this->invalid("graph.nodes.{$id}", 'Selecione um campo personalizado e um prazo para aguardar resposta.');
            }
            if ($node['type'] === 'ai') {
                foreach (['ai_media' => 'asset_id', 'ai_tags' => 'tag_id', 'ai_fields' => 'field_id'] as $list => $resourceKey) {
                    foreach ($data[$list] ?? [] as $index => $permission) {
                        $resource = $permission[$resourceKey] ?? null;
                        $validResource = match ($list) {
                            'ai_media' => in_array($resource, $assets, true),
                            'ai_tags' => in_array($resource, $tags, true),
                            'ai_fields' => $workspace->contactCustomFields()->where('type', 'text')->whereKey($resource)->exists(),
                        };
                        if (! $validResource || trim((string) ($permission['instruction'] ?? '')) === '') {
                            $this->invalid("graph.nodes.{$id}.{$list}.{$index}", 'Selecione um recurso deste workspace e preencha sua instrução.');
                        }
                        if ($list === 'ai_media') {
                            try {
                                app(FlowMedia::class)->contents(FlowAsset::findOrFail($resource));
                            } catch (\Throwable) {
                                $this->invalid("graph.nodes.{$id}.{$list}.{$index}", 'O arquivo está inválido ou indisponível. Envie uma mídia compatível de até 20 MiB.');
                            }
                        }
                    }
                }
                if (trim(ContactTemplate::display($data['ai_prompt'] ?? '')) === '' || ! in_array($data['ai_model'] ?? '', ['gpt-6-luna', 'gpt-6.1-sol', 'gpt-6-astra'], true)
                    || ! isset($data['ai_inactivity_seconds']) || ! in_array($data['ai_expiry_action'] ?? '', ['end', 'human'], true)
                    || (! empty($data['ai_max_messages']) && ! in_array($data['ai_limit_action'] ?? '', ['continue', 'human'], true))) {
                    $this->invalid("graph.nodes.{$id}", 'Configure prompt, modelo, inatividade e ação do limite no bloco IA.');
                }
                $integration = WorkspaceOpenAiIntegration::where('workspace_id', $workspace->id)->first();
                if (! $integration) {
                    $this->invalid("graph.nodes.{$id}", 'Configure a chave OpenAI deste workspace antes de publicar.');
                }
                try {
                    $available = Http::withToken($integration->api_key)->acceptJson()->timeout(10)
                        ->get('https://api.openai.com/v1/models/'.$data['ai_model'])->successful();
                    $transcriptionAvailable = Http::withToken($integration->api_key)->acceptJson()->timeout(10)
                        ->get('https://api.openai.com/v1/models/gpt-transcribe')->successful();
                } catch (\Throwable) {
                    $available = false;
                    $transcriptionAvailable = false;
                }
                if (! $available || ! $transcriptionAvailable) {
                    $this->invalid("graph.nodes.{$id}", 'O modelo IA ou a transcrição de áudio não estão disponíveis para a chave deste workspace.');
                }
            }
            if ($node['type'] === 'condition') {
                $issues = app(FlowCondition::class)->issues($data, $workspace);
                if ($issues !== []) {
                    $errors = [];
                    foreach ($issues as $path => $message) {
                        $errors["graph.nodes.{$id}.{$path}"] = 'Bloco “'.$data['label'].'”: '.$message;
                    }
                    throw ValidationException::withMessages($errors);
                }
            }
            if ($node['type'] === 'jump_flow') {
                $target = Flow::query()->where('workspace_id', $workspace->id)->whereKey($data['target_flow_id'] ?? 0)->first();
                if (! $target || ! $target->is_active || ! $target->published_version_id || $target->id === $flow->id) {
                    $this->invalid("graph.nodes.{$id}", 'Escolha outro fluxo publicado e ativo deste workspace.');
                }
            }
            if ($node['type'] === 'operations') {
                $actions = $data['actions'] ?? [];
                if ($actions === []) {
                    $this->invalid("graph.nodes.{$id}", 'Adicione ao menos uma operação.');
                }
                foreach ($actions as $index => $action) {
                    if ($action['kind'] === 'add_to_sequence' && ! Sequence::where('workspace_id', $workspace->id)->where('is_active', true)->whereNotNull('published_version_id')->whereKey($action['sequence_id'] ?? 0)->exists()) {
                        $this->invalid("graph.nodes.{$id}", 'Selecione uma sequência publicada e ativa deste workspace.');
                    }
                    if (in_array($action['kind'], ['open_human', 'pause_automation'], true) && $index !== count($actions) - 1) {
                        $this->invalid("graph.nodes.{$id}", 'Abrir atendimento ou pausar automações precisa ser a última operação.');
                    }
                    if (in_array($action['kind'], ['add_tag', 'remove_tag'], true) && ! in_array((int) ($action['tag_id'] ?? 0), $tags, true)) {
                        $this->invalid("graph.nodes.{$id}", 'Selecione uma etiqueta válida.');
                    }
                    if ($action['kind'] === 'set_field' && (! in_array((int) ($action['field_id'] ?? 0), $fields, true) || ! array_key_exists('value', $action))) {
                        $this->invalid("graph.nodes.{$id}", 'Selecione um campo e informe o valor.');
                    }
                    if ($action['kind'] === 'notify_member' && (! in_array((int) ($action['user_id'] ?? 0), $users, true) || trim((string) ($action['text'] ?? '')) === '')) {
                        $this->invalid("graph.nodes.{$id}", 'Selecione um membro da equipe e escreva a notificação.');
                    }
                    if ($action['kind'] === 'open_human' && ! empty($action['user_id']) && ! in_array((int) $action['user_id'], $members, true)) {
                        $this->invalid("graph.nodes.{$id}", 'Selecione um membro válido para receber o atendimento.');
                    }
                    if ($action['kind'] === 'pause_automation' && (($action['pause_mode'] ?? '') === 'timed' ? empty($action['pause_seconds']) : ($action['pause_mode'] ?? '') !== 'indefinite')) {
                        $this->invalid("graph.nodes.{$id}", 'Configure o período da pausa.');
                    }
                }
            }
        }
        foreach ($reachableNodes->where('type', 'jump_flow') as $node) {
            $targetId = (int) ($node['data']['target_flow_id'] ?? 0);
            if ($this->flowReaches($targetId, $flow->id, $workspace->id, [])) {
                $this->invalid("graph.nodes.{$node['id']}", 'Esse destino criaria um ciclo entre fluxos.');
            }
        }

        return $graph;
    }

    private function flowReaches(int $from, int $goal, int $workspaceId, array $seen): bool
    {
        if ($from === $goal) {
            return true;
        }
        if (isset($seen[$from])) {
            return false;
        }
        $seen[$from] = true;
        $target = Flow::query()->where('workspace_id', $workspaceId)->whereKey($from)->first();
        if (! $target?->publishedVersion) {
            return false;
        }
        foreach ($target->publishedVersion->graph['nodes'] ?? [] as $node) {
            if (($node['type'] ?? null) === 'jump_flow' && $this->flowReaches((int) ($node['data']['target_flow_id'] ?? 0), $goal, $workspaceId, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
