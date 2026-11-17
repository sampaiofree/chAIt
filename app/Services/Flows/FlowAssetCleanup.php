<?php

namespace App\Services\Flows;

use App\Models\ChatOperation;
use App\Models\Flow;
use App\Models\FlowAsset;
use App\Models\FlowDraftSession;
use App\Models\FlowExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FlowAssetCleanup
{
    public function ids(array $graph): array
    {
        $ids = [];
        array_walk_recursive($graph, function ($value, $key) use (&$ids) {
            if ($key === 'asset_id' && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        });

        return array_unique($ids);
    }

    public function request(Flow $flow, array $ids): void
    {
        foreach (FlowAsset::where('flow_id', $flow->id)->where('workspace_id', $flow->workspace_id)->whereIn('id', $ids)->get() as $asset) {
            DB::table('flow_asset_deletions')->insertOrIgnore([
                'asset_id' => $asset->id, 'workspace_id' => $asset->workspace_id, 'flow_id' => $asset->flow_id,
                'disk' => $asset->disk, 'path' => $asset->path, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::afterCommit(fn () => $this->run($flow->id));
    }

    public function run(?int $flowId = null): void
    {
        DB::table('flow_asset_deletions')->when($flowId, fn ($q) => $q->where('flow_id', $flowId))->orderBy('id')->eachById(function ($request) {
            try {
                DB::transaction(function () use ($request) {
                    DB::table('workspaces')->where('id', $request->workspace_id)->lockForUpdate()->first();
                    $flow = Flow::whereKey($request->flow_id)->lockForUpdate()->first();
                    $pending = DB::table('flow_asset_deletions')->where('id', $request->id)->lockForUpdate()->first();
                    if (! $pending) {
                        return;
                    }
                    $graphs = $flow ? [$flow->graph] : [];
                    if ($flow) {
                        $draft = FlowDraftSession::where('flow_id', $flow->id)->first();
                        $graphs[] = $draft?->graph ?? [];
                        if ($flow->is_active) {
                            $graphs[] = $flow->publishedVersion?->graph ?? [];
                        }
                        foreach (FlowExecution::where('flow_id', $flow->id)->whereIn('status', FlowExecutionManager::ACTIVE)->with('version')->get() as $execution) {
                            $graphs[] = $execution->version?->graph ?? [];
                        }
                    }
                    foreach ($graphs as $graph) {
                        if (in_array((int) $request->asset_id, $this->ids($graph), true)) {
                            return;
                        }
                    }
                    if (ChatOperation::where('workspace_id', $request->workspace_id)
                        ->whereIn('status', ['pending', 'running', 'confirmation_pending'])
                        ->get()->contains(fn ($operation) => (int) data_get($operation->payload, 'asset_id') === (int) $request->asset_id)) {
                        return;
                    }
                    $disk = Storage::disk($request->disk);
                    if ($disk->exists($request->path) && ! $disk->delete($request->path)) {
                        throw new \RuntimeException('storage_delete_failed');
                    }
                    FlowAsset::whereKey($request->asset_id)->delete();
                    DB::table('flow_asset_deletions')->where('id', $request->id)->delete();
                });
            } catch (\Throwable) {
                Log::warning('Flow asset cleanup deferred.', ['deletion_id' => $request->id, 'asset_id' => $request->asset_id]);
                DB::table('flow_asset_deletions')->where('id', $request->id)->update([
                    'attempts' => DB::raw('attempts + 1'), 'error' => 'storage_cleanup_failed', 'updated_at' => now(),
                ]);
            }
        });
    }
}
