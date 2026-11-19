<?php

namespace App\Services\Contacts;

use App\Jobs\CleanupDeletedLeadAi;
use App\Models\AiSession;
use App\Models\ChatOperation;
use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\AuditLogService;
use App\Services\Chat\ChatPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LeadDeletion
{
    public function delete(Workspace $workspace, Contact $lead, User $actor): void
    {
        DB::transaction(function () use ($actor, $workspace, $lead) {
            $identities = $lead->channels()->where('type', 'whatsapp')->pluck('external_id')
                ->merge($lead->threads()->pluck('external_chat_id'))->filter();
            if ($lead->phone) {
                $identities->push($lead->phone.'@s.whatsapp.net');
            }
            $reset = app(ContactReset::class);
            foreach ($identities->unique()->sort() as $external) {
                $reset->lock($workspace->id, $external);
            }
            $lead = Contact::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            foreach ($identities->unique() as $external) {
                DB::table('contact_resets')->updateOrInsert(['workspace_id' => $workspace->id, 'external_id' => $external], ['reset_at' => now()->format('Y-m-d H:i:s.u')]);
                DB::table('uazapi_inbox')->where('workspace_id', $workspace->id)->where('chat_id', $external)->where('status', 'pending')->update(['status' => 'processed', 'decision' => 'lead_deleted', 'payload' => null]);
            }
            $threads = $lead->threads()->get();
            $executions = FlowExecution::where('contact_id', $lead->id)->pluck('id');
            foreach (AiSession::where('contact_id', $lead->id)->get() as $session) {
                if ($session->remote_conversation_id && ! $session->cleanup_completed_at) {
                    $key = $session->cleanup_api_key ?: WorkspaceOpenAiIntegration::where('workspace_id', $workspace->id)->first()?->api_key;
                    if ($key) {
                        CleanupDeletedLeadAi::dispatch($session->remote_conversation_id, encrypt($key))->afterCommit();
                    }
                }
            }
            foreach (ChatOperation::whereIn('thread_id', $threads->pluck('id'))->get() as $operation) {
                if ($path = data_get($operation->payload, 'file_path')) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($path));
                }
            }
            DB::table('flow_events')->whereIn('flow_execution_id', $executions)->delete();
            // Executions reference enrollments with RESTRICT, so remove them first.
            DB::table('flow_executions')->whereIn('id', $executions)->delete();
            app(AuditLogService::class)->record($workspace, 'lead.deleted', $actor, $lead);
            $lead->delete();
            foreach ($threads as $thread) {
                app(ChatPublisher::class)->send($workspace->id, 'conversation.removed', ['id' => $thread->id]);
            }
        }, 3);
    }
}
