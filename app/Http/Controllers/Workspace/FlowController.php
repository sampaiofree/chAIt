<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\FlowAsset;
use App\Models\FlowFolder;
use App\Models\Sequence;
use App\Models\UazapiConnection;
use App\Models\Workspace;
use App\Services\FlowAssistant\AssistantAccess;
use App\Services\FlowAssistant\DraftSessionService;
use App\Services\Flows\FlowCapabilities;
use App\Services\Flows\FlowGraphValidator;
use App\Services\Flows\FlowMedia;
use App\Services\Sequences\SequenceDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FlowController extends Controller
{
    use EnsuresWorkspaceAccess;

    public function index(Request $request, Workspace $workspace): Response
    {
        $this->ensureCanManage($request, $workspace);
        $data = $request->validate(['folder_id' => ['nullable', 'integer', 'exists:flow_folders,id,workspace_id,'.$workspace->id]]);
        $folderId = isset($data['folder_id']) ? (int) $data['folder_id'] : null;
        $folders = $workspace->flowFolders()->withCount('flows')->orderBy('name')->get(['id', 'parent_id', 'name']);
        $byId = $folders->keyBy('id');
        $breadcrumbs = [['id' => null, 'name' => 'Área principal']];
        $ancestors = [];
        $cursor = $folderId;
        $visited = [];
        while ($cursor !== null && isset($byId[$cursor]) && ! isset($visited[$cursor])) {
            $visited[$cursor] = true;
            $folder = $byId[$cursor];
            $ancestors[] = ['id' => $folder->id, 'name' => $folder->name];
            $cursor = $folder->parent_id;
        }
        $breadcrumbs = array_merge($breadcrumbs, array_reverse($ancestors));

        return Inertia::render('flows/index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'current_folder_id' => $folderId,
            'breadcrumbs' => $breadcrumbs,
            'folders' => $folders,
            'flows' => $workspace->flows()->orderBy('name')->get(['id', 'folder_id', 'name', 'revision', 'updated_at', 'is_active', 'published_version_id']),
        ]);
    }

    public function store(Request $request, Workspace $workspace, FlowGraphValidator $validator): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'folder_id' => ['nullable', 'integer', 'exists:flow_folders,id,workspace_id,'.$workspace->id],
        ]);
        $id = (string) str()->uuid();
        $flow = $workspace->flows()->create([
            'name' => $data['name'],
            'folder_id' => $data['folder_id'] ?? null,
            'graph' => $validator->validate([
                'schema_version' => 2,
                'nodes' => [[
                    'id' => $id,
                    'type' => 'start',
                    'position' => ['x' => 80, 'y' => 100],
                    'data' => ['label' => 'Start', 'description' => 'Início do fluxo'],
                ]],
                'edges' => [],
                'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
            ]),
            'revision' => 1,
        ]);

        return response()->json(['data' => $flow], 201);
    }

    public function storeFolder(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', 'exists:flow_folders,id,workspace_id,'.$workspace->id],
        ]);
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $folder = DB::transaction(function () use ($workspace, $data, $parentId): FlowFolder {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($parentId !== null) {
                $workspace->flowFolders()->findOrFail($parentId);
            }
            $this->ensureFolderNameAvailable($workspace, $parentId, $data['name']);

            return $workspace->flowFolders()->create(['name' => $data['name'], 'parent_id' => $parentId]);
        });

        return response()->json(['data' => $folder], 201);
    }

    public function updateFolder(Request $request, Workspace $workspace, int $folder): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        if ($request->exists('name')) {
            $request->merge(['name' => trim((string) $request->input('name'))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:flow_folders,id,workspace_id,'.$workspace->id],
        ]);
        if (! array_key_exists('name', $data) && ! array_key_exists('parent_id', $data)) {
            throw ValidationException::withMessages(['name' => 'Informe um nome ou uma pasta de destino.']);
        }
        $folder = DB::transaction(function () use ($workspace, $folder, $data): FlowFolder {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $model = $workspace->flowFolders()->whereKey($folder)->lockForUpdate()->firstOrFail();
            $parentId = array_key_exists('parent_id', $data)
                ? ($data['parent_id'] !== null ? (int) $data['parent_id'] : null)
                : $model->parent_id;
            if ($parentId !== null) {
                $workspace->flowFolders()->findOrFail($parentId);
                if (in_array($parentId, $this->folderSubtreeIds($workspace, $model->id), true)) {
                    throw ValidationException::withMessages(['parent_id' => 'Uma pasta não pode ser movida para dentro de si mesma.']);
                }
            }
            $name = $data['name'] ?? $model->name;
            $this->ensureFolderNameAvailable($workspace, $parentId, $name, $model->id);
            $model->update(['name' => $name, 'parent_id' => $parentId]);

            return $model->fresh();
        });

        return response()->json(['data' => $folder]);
    }

    public function deleteFolder(Request $request, Workspace $workspace, int $folder): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        DB::transaction(function () use ($workspace, $folder): void {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $model = $workspace->flowFolders()->whereKey($folder)->lockForUpdate()->firstOrFail();
            $ids = $this->folderSubtreeIds($workspace, $model->id);
            $workspace->flows()->whereIn('folder_id', $ids)->update(['folder_id' => null]);
            foreach (array_reverse($ids) as $id) {
                $workspace->flowFolders()->whereKey($id)->delete();
            }
        });

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function moveToFolder(Request $request, Workspace $workspace, int $flow): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $data = $request->validate([
            'folder_id' => ['nullable', 'integer', 'exists:flow_folders,id,workspace_id,'.$workspace->id],
        ]);
        $flow = $workspace->flows()->findOrFail($flow);
        $flow->update(['folder_id' => $data['folder_id'] ?? null]);

        return response()->json(['data' => ['folder_id' => $flow->folder_id]]);
    }

    public function destroy(Request $request, Workspace $workspace, int $flow): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $assets = DB::transaction(function () use ($workspace, $flow) {
            $model = $workspace->flows()->whereKey($flow)->lockForUpdate()->firstOrFail();
            if ($model->is_active || $model->published_version_id !== null || $model->versions()->exists()
                || DB::table('flow_executions')->where('flow_id', $model->id)->exists()) {
                abort(409, 'Fluxos publicados ou com histórico de execução não podem ser excluídos.');
            }
            if (DB::table('flow_trigger_settings')->where('workspace_id', $workspace->id)
                ->where(fn ($query) => $query->where('welcome_flow_id', $model->id)->orWhere('default_flow_id', $model->id))->exists()
                || DB::table('flow_keyword_triggers')->where('workspace_id', $workspace->id)->where('flow_id', $model->id)->exists()) {
                abort(409, 'Remova os gatilhos que usam este fluxo antes de excluí-lo.');
            }
            foreach ($workspace->flows()->where('id', '<>', $model->id)->get(['id', 'graph']) as $other) {
                foreach ($other->graph['nodes'] ?? [] as $node) {
                    if (($node['type'] ?? null) === 'jump_flow' && (int) ($node['data']['target_flow_id'] ?? 0) === $model->id) {
                        abort(409, 'Remova as referências “Ir para Fluxo” antes de excluir este fluxo.');
                    }
                }
            }
            $assets = FlowAsset::where('workspace_id', $workspace->id)->where('flow_id', $model->id)->get(['disk', 'path']);
            $references = app(SequenceDefinitionService::class)->flowReferences($workspace->id, $model->id, true);
            abort_if($references !== [], 409, 'Este fluxo possui versões preservadas nas sequências: '.implode(', ', $references).'.');
            $model->delete();

            return $assets;
        });
        foreach ($assets as $asset) {
            try {
                Storage::disk($asset->disk)->delete($asset->path);
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function edit(Request $request, Workspace $workspace, int $flow): Response
    {
        $this->ensureCanManage($request, $workspace);
        $flow = $workspace->flows()->findOrFail($flow);

        return Inertia::render('flows/editor', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'flow' => array_merge($flow->toArray(), ['graph' => app(FlowGraphValidator::class)->normalize($flow->graph)]),
            'catalog' => app(FlowCapabilities::class)->catalog($workspace),
            'reference' => [
                'tags' => $workspace->tags()->orderBy('name')->get(['id', 'name']),
                'fields' => $workspace->contactCustomFields()->orderBy('name')->get(['id', 'name', 'type']),
                'members' => $workspace->memberships()->whereNotNull('joined_at')->with('user:id,name,phone')->get()->map(fn ($m) => ['id' => $m->user_id, 'name' => $m->user->name, 'phone' => $m->user->phone])->values(),
                'flows' => $workspace->flows()->where('id', '<>', $flow->id)->where('is_active', true)->whereNotNull('published_version_id')->orderBy('name')->get(['id', 'name']),
                'sequences' => Sequence::where('workspace_id', $workspace->id)->where('is_active', true)->whereNotNull('published_version_id')->orderBy('name')->get(['id', 'name']),
                'assets' => FlowAsset::where('flow_id', $flow->id)->where('workspace_id', $workspace->id)->get()->map(fn ($asset) => [...$asset->only(['id', 'original_name', 'mime_type', 'size']), 'available' => $asset->disk === 'local' && Storage::disk($asset->disk)->exists($asset->path)]),
                'published_version' => $flow->publishedVersion?->version,
                'is_active' => $flow->is_active,
            ],
            'recent_executions' => DB::table('flow_executions')->where('flow_id', $flow->id)->orderByDesc('id')->limit(20)->get(['id', 'status', 'trigger_source', 'started_at', 'finished_at', 'error_message']),
        ]);
    }

    public function events(Request $request, Workspace $workspace, int $flow): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $workspace->flows()->findOrFail($flow);
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $query = DB::table('flow_events as e')->join('flow_executions as x', 'x.id', '=', 'e.flow_execution_id')
            ->where('e.workspace_id', $workspace->id)->where('x.workspace_id', $workspace->id)->where('x.flow_id', $flow);
        if (isset($data['before'])) {
            $query->where('e.id', '<', $data['before']);
        }
        $rows = $query->orderByDesc('e.id')->limit(51)->get(['e.id', 'e.flow_execution_id', 'x.flow_version_id', 'e.event_type', 'e.node_id', 'e.metadata', 'e.created_at']);
        $more = $rows->count() > 50;
        $rows = $rows->take(50)->map(function ($row) {
            $row->metadata = array_intersect_key(json_decode($row->metadata, true) ?? [], array_flip(['item_id', 'field', 'reason', 'outcome', 'version_id', 'handle', 'from']));

            return $row;
        });

        return response()->json(['data' => $rows->values(), 'next_before' => $more ? $rows->last()->id : null]);
    }

    public function rename(Request $request, Workspace $workspace, int $flow): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $flow = $workspace->flows()->findOrFail($flow);
        app(DraftSessionService::class)->locked($flow, function (Flow $locked) use ($data) {
            abort_if(app(DraftSessionService::class)->current($locked), 409, 'Este fluxo possui uma prévia compartilhada. Renomeie no editor.');
            $locked->update(['name' => $data['name'], 'revision' => $locked->revision + 1]);
        });

        return response()->json(['data' => $flow->fresh()]);
    }

    public function update(Request $request, Workspace $workspace, int $flow, FlowGraphValidator $validator): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $flow = $workspace->flows()->findOrFail($flow);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'graph' => ['required', 'array'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        $data += $request->validate(['generation' => ['nullable', 'uuid'], 'draft_revision' => ['nullable', 'integer', 'min:1']]);
        if (app(DraftSessionService::class)->current($flow)) {
            app(AssistantAccess::class)->check($request->user(), $flow);
        }
        $updated = app(DraftSessionService::class)->save($flow, $data + ['actor_user_id' => $request->user()->id]);

        return response()->json(['data' => $updated]);
    }

    public function publish(Request $request, Workspace $workspace, int $flowId, FlowGraphValidator $validator): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $flow = $workspace->flows()->whereKey($flowId)->firstOrFail();
        abort_unless($flow->revision === (int) $data['revision'], 409, 'Este fluxo foi alterado em outra aba. Recarregue antes de publicar.');
        abort_if(app(DraftSessionService::class)->current($flow), 409, 'Salve ou descarte a prévia compartilhada antes de publicar.');
        $graph = $validator->validateForPublish($flow->graph, $workspace, $flow);
        $version = DB::transaction(function () use ($flow, $graph, $request, $data, $workspace, $validator) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $locked = Flow::query()->whereKey($flow->id)->lockForUpdate()->firstOrFail();
            abort_if(app(DraftSessionService::class)->current($locked), 409, 'Salve ou descarte a prévia antes de publicar.');
            abort_unless($locked->revision === (int) $data['revision'], 409, 'Este fluxo foi alterado em outra aba. Recarregue antes de publicar.');
            $validator->validateResourceReferences($graph, $workspace);
            $versionNumber = ((int) $locked->versions()->max('version')) + 1;
            $version = $locked->versions()->create([
                'workspace_id' => $locked->workspace_id, 'version' => $versionNumber, 'graph' => $graph,
                'published_by_user_id' => $request->user()->id, 'published_at' => now(),
            ]);
            $locked->forceFill(['published_version_id' => $version->id, 'is_active' => true])->save();

            return $version;
        });

        return response()->json(['data' => ['version' => $version->version, 'is_active' => true]]);
    }

    public function deactivate(Request $request, Workspace $workspace, int $flowId): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);

        return DB::transaction(function () use ($workspace, $flowId): JsonResponse {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $flow = $workspace->flows()->whereKey($flowId)->firstOrFail();
            $referencing = $workspace->flows()->where('id', '<>', $flow->id)->where('is_active', true)->get()
                ->filter(function (Flow $other) use ($flow): bool {
                    foreach ($other->publishedVersion?->graph['nodes'] ?? [] as $node) {
                        if (($node['type'] ?? '') === 'jump_flow' && (int) ($node['data']['target_flow_id'] ?? 0) === $flow->id) {
                            return true;
                        }
                    }

                    return false;
                })->pluck('name')->values();
            if ($referencing->isNotEmpty()) {
                return response()->json(['message' => 'Remova as referências “Ir para Fluxo” dos fluxos ativos: '.$referencing->join(', ').'.'], 409);
            }
            $references = app(SequenceDefinitionService::class)->flowReferences($workspace->id, $flow->id);
            abort_if($references !== [], 409, 'Este fluxo é usado por sequências ativas ou participações vigentes: '.implode(', ', $references).'.');
            $flow->update(['is_active' => false]);

            return response()->json(['data' => ['is_active' => false]]);
        });
    }

    public function upload(Request $request, Workspace $workspace, int $flowId): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $flow = $workspace->flows()->findOrFail($flowId);
        $data = $request->validate(['file' => ['required', 'file', 'max:20480']]);
        $file = $data['file'];
        $mime = (string) $file->getMimeType();
        $type = FlowMedia::type($mime);
        if (! $type || ! FlowMedia::validExtension($file->getClientOriginalName(), $mime)) {
            throw ValidationException::withMessages(['file' => 'Envie imagem, vídeo MP4, áudio MP3/OGG ou documento compatível.']);
        }
        $path = $file->store("flows/{$workspace->id}/{$flow->id}", 'local');
        $asset = FlowAsset::create(['workspace_id' => $workspace->id, 'flow_id' => $flow->id, 'disk' => 'local', 'path' => $path,
            'original_name' => $file->getClientOriginalName(), 'mime_type' => $mime, 'size' => $file->getSize()]);

        return response()->json(['data' => ['id' => $asset->id, 'media_type' => $type, 'original_name' => $asset->original_name, 'mime_type' => $mime, 'size' => $asset->size]], 201);
    }

    public function storeField(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $field = $workspace->contactCustomFields()->firstOrCreate(['name' => trim($data['name'])], ['type' => 'text']);

        return response()->json(['data' => $field], $field->wasRecentlyCreated ? 201 : 200);
    }

    public function storeTag(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $tag = $workspace->tags()->firstOrCreate(['name' => trim($data['name'])], ['color' => '#16a34a', 'created_by_user_id' => $request->user()->id]);

        return response()->json(['data' => $tag], $tag->wasRecentlyCreated ? 201 : 200);
    }

    public function triggers(Request $request, Workspace $workspace): Response
    {
        $this->ensureCanManage($request, $workspace);
        $connection = UazapiConnection::where('workspace_id', $workspace->id)->first();
        abort_unless($connection, 409, 'Configure primeiro uma conexão WhatsApp.');

        return Inertia::render('flows/triggers', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'connection' => ['id' => $connection->id, 'phone' => $connection->phone, 'status' => $connection->status],
            'flows' => $workspace->flows()->whereNotNull('published_version_id')->orderBy('name')->get(['id', 'name', 'is_active']),
            'settings' => DB::table('flow_trigger_settings')->where('uazapi_connection_id', $connection->id)->first(),
            'keywords' => DB::table('flow_keyword_triggers')->where('uazapi_connection_id', $connection->id)->orderBy('position')->get(),
        ]);
    }

    public function saveTriggers(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);
        $connection = UazapiConnection::where('workspace_id', $workspace->id)->firstOrFail();
        $data = $request->validate([
            'welcome_flow_id' => ['nullable', 'integer'], 'default_flow_id' => ['nullable', 'integer'],
            'keywords' => ['present', 'array', 'max:100'], 'keywords.*.id' => ['nullable', 'integer'],
            'keywords.*.term' => ['required', 'string', 'max:191'], 'keywords.*.operator' => ['required', 'in:exact,prefix,contains'],
            'keywords.*.flow_id' => ['required', 'integer'], 'keywords.*.interrupt_active' => ['required', 'boolean'],
        ]);
        $publishedIds = $workspace->flows()->whereNotNull('published_version_id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (['welcome_flow_id', 'default_flow_id'] as $key) {
            if (! empty($data[$key]) && ! in_array((int) $data[$key], $publishedIds, true)) {
                throw ValidationException::withMessages([$key => 'Selecione um fluxo publicado deste workspace.']);
            }
        }
        foreach ($data['keywords'] as $keyword) {
            if (! in_array((int) $keyword['flow_id'], $publishedIds, true)) {
                throw ValidationException::withMessages(['keywords' => 'Todas as palavras-chave precisam apontar para fluxos publicados deste workspace.']);
            }
        }
        DB::transaction(function () use ($workspace, $connection, $data) {
            DB::table('flow_trigger_settings')->updateOrInsert(['uazapi_connection_id' => $connection->id], [
                'workspace_id' => $workspace->id, 'welcome_flow_id' => $data['welcome_flow_id'] ?? null,
                'default_flow_id' => $data['default_flow_id'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('flow_keyword_triggers')->where('uazapi_connection_id', $connection->id)->delete();
            foreach ($data['keywords'] as $position => $keyword) {
                DB::table('flow_keyword_triggers')->insert([
                    'workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'flow_id' => $keyword['flow_id'],
                    'term' => trim($keyword['term']), 'operator' => $keyword['operator'], 'interrupt_active' => $keyword['interrupt_active'],
                    'position' => $position, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['data' => ['saved' => true]]);
    }

    /** @return array<int> */
    private function folderSubtreeIds(Workspace $workspace, int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier !== []) {
            $children = $workspace->flowFolders()->whereIn('parent_id', $frontier)->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
            $frontier = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function ensureFolderNameAvailable(Workspace $workspace, ?int $parentId, string $name, ?int $exceptId = null): void
    {
        $siblings = $workspace->flowFolders()->where('parent_id', $parentId)->get(['id', 'name']);
        if ($siblings->contains(fn (FlowFolder $folder): bool => $folder->id !== $exceptId
            && mb_strtolower($folder->name) === mb_strtolower($name))) {
            throw ValidationException::withMessages(['name' => 'Já existe uma pasta com este nome neste local.']);
        }
    }

    private function ensureCanManage(Request $request, Workspace $workspace): void
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace);
        abort_unless($request->user()->canManageFlows($workspace), 403, 'Você não tem permissão para editar fluxos neste workspace.');
    }
}
