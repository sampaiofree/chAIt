<?php

namespace App\Services\Contacts;

use App\Models\ExternalWebhook;
use App\Models\FlowDraftSession;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\SequenceEnrollment;
use App\Models\Workspace;
use App\Services\Flows\ContactTemplate;
use App\Services\Flows\FlowCondition;
use App\Services\Flows\FlowExecutionManager;
use Illuminate\Support\Facades\DB;

class ContactResourceReferences
{
    /** Call under the workspace row lock, also used by publishers and webhook writes. */
    public function find(Workspace $workspace, string $kind, int $id): array
    {
        $versions = $workspace->flows()->whereNotNull('published_version_id')->pluck('published_version_id')->all();
        $versions = array_merge($versions, FlowExecution::where('workspace_id', $workspace->id)->whereIn('status', FlowExecutionManager::ACTIVE)->pluck('flow_version_id')->all());
        $dependencies = DB::table('sequence_version_flows as dependencies')
            ->join('sequence_versions as versions', 'versions.id', '=', 'dependencies.sequence_version_id')
            ->join('sequences', 'sequences.id', '=', 'versions.sequence_id')
            ->where('sequences.workspace_id', $workspace->id)
            ->where(function ($query) {
                $query->where(fn ($active) => $active->where('sequences.is_active', true)->whereColumn('sequences.published_version_id', 'versions.id'))
                    ->orWhereExists(fn ($enrolled) => $enrolled->selectRaw('1')->from('sequence_enrollments')->whereColumn('sequence_version_id', 'versions.id')->whereNotIn('status', SequenceEnrollment::TERMINAL));
            })->get(['dependencies.flow_version_id', 'versions.name']);
        $versions = array_unique(array_merge($versions, $dependencies->pluck('flow_version_id')->all()));
        $references = [];
        if ($kind === 'field') {
            foreach ($workspace->flows()->get(['id', 'name', 'graph']) as $flow) {
                if ($this->graphUses($flow->graph, $kind, $id)) {
                    $references[] = 'Rascunho do fluxo “'.$flow->name.'”';
                }
            }
            foreach (FlowDraftSession::where('workspace_id', $workspace->id)->with('flow:id,name')->get() as $session) {
                if ($this->graphUses($session->graph, $kind, $id)) {
                    $references[] = 'Prévia do fluxo “'.($session->flow?->name ?? $session->flow_id).'”';
                }
            }
        }
        foreach (FlowVersion::with('flow:id,name')->where('workspace_id', $workspace->id)->whereIn('id', $versions)->get() as $version) {
            if (! $this->graphUses($version->graph, $kind, $id)) {
                continue;
            }
            $references[] = 'Fluxo “'.($version->flow?->name ?? $version->flow_id).'” (versão '.$version->version.')';
            foreach ($dependencies->where('flow_version_id', $version->id) as $dependency) {
                $references[] = 'Sequência “'.$dependency->name.'”';
            }
        }
        foreach (ExternalWebhook::where('workspace_id', $workspace->id)->where('status', 'active')->get() as $webhook) {
            $used = $kind === 'field'
                ? collect($webhook->mappings)->contains(fn ($mapping) => ($mapping['target_kind'] ?? '') === 'custom' && (int) ($mapping['target'] ?? 0) === $id)
                : collect($webhook->tag_rules)->contains(fn ($rule) => in_array($id, array_merge($rule['add'] ?? [], $rule['remove'] ?? []), true));
            if ($used) {
                $references[] = 'Webhook “'.$webhook->name.'”';
            }
        }

        return array_values(array_unique($references));
    }

    private function graphUses(array $graph, string $kind, int $id): bool
    {
        if ($kind === 'field' && in_array($id, ContactTemplate::fieldIds($graph), true)) {
            return true;
        }
        $key = $kind === 'tag' ? 'tag_id' : 'field_id';
        foreach ($graph['nodes'] ?? [] as $node) {
            $data = $node['data'] ?? [];
            if (($node['type'] ?? '') === 'condition') {
                foreach (app(FlowCondition::class)->normalize($data)['checks'] ?? [] as $check) {
                    if (($check['kind'] ?? '') === $kind && (int) ($check[$key] ?? 0) === $id) {
                        return true;
                    }
                }
            }
            if (($node['type'] ?? '') === 'wait_reply' && $kind === 'field' && (int) ($data['field_id'] ?? 0) === $id) {
                return true;
            }
            if (($node['type'] ?? '') === 'operations') {
                foreach ($data['actions'] ?? [] as $action) {
                    $kinds = $kind === 'tag' ? ['add_tag', 'remove_tag'] : ['set_field'];
                    if (in_array($action['kind'] ?? '', $kinds, true) && (int) ($action[$key] ?? 0) === $id) {
                        return true;
                    }
                }
            }
            if (($node['type'] ?? '') === 'ai') {
                foreach ($data[$kind === 'tag' ? 'ai_tags' : 'ai_fields'] ?? [] as $permission) {
                    if ((int) ($permission[$key] ?? 0) === $id) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
