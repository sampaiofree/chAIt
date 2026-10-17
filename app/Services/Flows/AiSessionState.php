<?php

namespace App\Services\Flows;

use App\Models\AiSession;

class AiSessionState
{
    public static function current(AiSession $session, bool $checkExpiry = true): bool
    {
        $session->refresh();
        $execution = $session->execution()->with(['version', 'thread', 'connection', 'contact'])->first();
        $session->setRelation('execution', $execution);
        $node = $execution ? collect($execution->version?->graph['nodes'] ?? [])->firstWhere('id', $session->node_id) : null;

        return $session->status === 'active' && (! $checkExpiry || now()->lt($session->expires_at))
            && $execution?->status === 'waiting_ai' && $execution->current_node_id === $session->node_id
            && ($node['type'] ?? null) === 'ai'
            && $execution->workspace_id === $session->workspace_id && $execution->contact_id === $session->contact_id
            && $execution->flow_version_id === $session->flow_version_id && $execution->thread_id === $session->thread_id
            && $execution->uazapi_connection_id === $session->uazapi_connection_id
            && $execution->version?->workspace_id === $session->workspace_id && $execution->version?->flow_id === $execution->flow_id
            && $execution->contact?->workspace_id === $session->workspace_id && $execution->connection?->workspace_id === $session->workspace_id
            && $execution->thread?->workspace_id === $session->workspace_id && $execution->thread?->contact_id === $session->contact_id
            && $execution->thread?->uazapi_connection_id === $session->uazapi_connection_id
            && ! app(FlowRuntime::class)->isPaused($execution);
    }
}
