<?php

namespace App\Services\ExternalWebhooks;

use App\Models\Contact;
use App\Models\ExternalWebhook;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Services\Chat\ChatProjection;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Flows\FlowRuntime;

class WebhookFlowStarter
{
    /** Called inside the webhook transaction, after contact and tags are saved. */
    public function start(ExternalWebhook $webhook, Contact $contact, int $flowId): array
    {
        $flow = Flow::where('workspace_id', $webhook->workspace_id)->whereKey($flowId)
            ->where('is_active', true)->with('publishedVersion')->first();
        if (! $flow?->publishedVersion) {
            return ['status' => 'flow_unavailable', 'flow_id' => $flowId];
        }
        $connection = UazapiConnection::where('workspace_id', $webhook->workspace_id)->first();
        if (! $connection || $connection->status !== 'connected' || ! $connection->instance_token) {
            return ['status' => 'connection_unavailable', 'flow_id' => $flowId];
        }

        $manager = app(FlowExecutionManager::class);

        return $manager->locked($webhook->workspace_id, $connection->id, $contact->id, function ($context) use ($manager, $connection, $contact, $flow, $flowId) {
            $blockers = array_values(array_diff($manager->blockers($context), ['flow']));
            if ($blockers !== []) {
                return ['status' => in_array('human', $blockers, true) ? 'human' : 'paused', 'flow_id' => $flowId];
            }
            $thread = Thread::where('workspace_id', $contact->workspace_id)->where('uazapi_connection_id', $connection->id)
                ->where('contact_id', $contact->id)->orderByDesc('id')->first();
            $thread ??= app(ChatProjection::class)->sync($connection, ['jid' => $contact->phone.'@s.whatsapp.net'], null, true);
            if (! $thread || $thread->contact_id !== $contact->id) {
                return ['status' => 'conversation_unavailable', 'flow_id' => $flowId];
            }
            if ($context->active_execution_id) {
                $current = FlowExecution::findOrFail($context->active_execution_id);
                app(FlowRuntime::class)->finish($current, 'interrupted', 'Substituído por fluxo de webhook.', 'webhook');
            }
            $execution = $manager->start($flow->publishedVersion, $contact->id, $thread->id, $connection, 'webhook');

            return ['status' => $execution ? 'started' : 'blocked', 'flow_id' => $flowId];
        });
    }
}
