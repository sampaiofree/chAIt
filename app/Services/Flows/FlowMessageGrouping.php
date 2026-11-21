<?php

namespace App\Services\Flows;

use App\Jobs\Chat\PrepareAiInput;
use App\Jobs\ExpireFlowWait;
use App\Jobs\ProcessFlowExecution;
use App\Jobs\ProcessFlowMessageBatch;
use App\Models\AiSession;
use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\FlowMessageBatch;
use App\Models\UazapiInbox;
use Carbon\CarbonInterface;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class FlowMessageGrouping
{
    public const DEFAULT_SECONDS = 5;

    // Inbound already holds the contact and execution locks.
    public function receive(FlowExecution $execution, ?AiSession $session, ?int $waitId, ?int $inboxId, string $kind, ?string $messageId = null, ?string $selection = null): bool
    {
        $inbox = $inboxId ? UazapiInbox::find($inboxId) : null;
        $at = $inbox?->accepted_at ? Carbon::parse($inbox->accepted_at) : ($inbox?->created_at ?? now());
        $owner = $session
            ? FlowMessageBatch::where('ai_session_id', $session->id)
            : FlowMessageBatch::where('flow_wait_id', $waitId);
        $batch = (clone $owner)->latest('id')->lockForUpdate()->first();

        if ($session && $at->gte($session->expires_at)) {
            return false;
        }
        if ($waitId && ! $batch) {
            $wait = DB::table('flow_waits')->where('id', $waitId)->first();
            if (! $wait || $at->gte($wait->expires_at)) {
                return false;
            }
        }
        if ($inboxId && $batch && collect($batch->entries)->contains('inbox', $inboxId)) {
            return false;
        }

        if ($batch && ($batch->sealed || $at->gte($batch->due_at) || $kind === 'media')) {
            if (! $batch->sealed) {
                $batch->update(['sealed' => true]);
            }
            if ($waitId) {
                $this->dispatch($batch);

                return false;
            }
            $this->dispatch($batch);
            $batch = null;
        }

        $data = collect($execution->version->graph['nodes'])->firstWhere('id', $execution->current_node_id)['data'] ?? [];
        $seconds = $this->interval($execution, $session !== null);
        $entry = ['inbox' => $inboxId, 'id' => $messageId, 'selection' => $selection];
        $newBatch = ! $batch;
        if ($newBatch) {
            $batch = FlowMessageBatch::create([
                'flow_execution_id' => $execution->id, 'flow_wait_id' => $waitId, 'ai_session_id' => $session?->id,
                'kind' => $kind, 'entries' => [$entry], 'sealed' => $kind === 'media',
                'due_at' => $kind === 'media' ? now() : $at->copy()->addSeconds($seconds),
                'cutoff_at' => $at->copy()->addSeconds(3 * $seconds),
            ]);
        } else {
            $batch->update([
                'entries' => [...$batch->entries, $entry],
                'due_at' => $at->copy()->addSeconds($seconds)->min($batch->cutoff_at),
            ]);
        }
        if ($inbox) {
            $inbox->increment('consumers');
        }
        if ($session) {
            $session->update(['expires_at' => now()->addSeconds((int) $data['ai_inactivity_seconds'])]);
        }
        if ($newBatch) {
            $this->dispatch($batch);
        }

        return true;
    }

    private function interval(FlowExecution $execution, bool $ai): int
    {
        $data = collect($execution->version->graph['nodes'])->firstWhere('id', $execution->current_node_id)['data'] ?? [];

        return (int) ($data[$ai ? 'ai_grouping_seconds' : 'reply_grouping_seconds'] ?? self::DEFAULT_SECONDS);
    }

    public function dispatch(FlowMessageBatch $batch, ?CarbonInterface $when = null): void
    {
        $due = $when ?? ($batch->sealed ? now() : $batch->due_at);
        if ($due->gt(now()) && Queue::connection() instanceof SyncQueue) {
            return;
        }
        // The queue works in whole seconds. Round up to avoid an early retry loop.
        ProcessFlowMessageBatch::dispatch($batch->id, $batch->ai_session_id !== null)
            ->delay(max(0, (int) ceil((float) now()->diffInSeconds($due, false))))->afterCommit();
    }

    public function pendingInbound(FlowExecution $execution, $until, $since = null): bool
    {
        return UazapiInbox::where('uazapi_connection_id', $execution->uazapi_connection_id)
            ->where('chat_id', $execution->thread?->external_chat_id)
            ->where('event_type', 'messages')->where('status', 'pending')->where('expires_at', '>', now())
            ->whereRaw('coalesce(accepted_at, created_at) < ?', [$until instanceof CarbonInterface ? $until->format('Y-m-d H:i:s.v') : $until])
            ->when($since, fn ($query) => $query->whereRaw('coalesce(accepted_at, created_at) >= ?', [
                $since instanceof CarbonInterface ? $since->format('Y-m-d H:i:s.v') : $since,
            ]))->exists();
    }

    public function process(int $id): void
    {
        $batch = FlowMessageBatch::find($id);
        if (! $batch) {
            return;
        }
        if (! $batch->ai_session_id) {
            $this->completeWait($batch);

            return;
        }

        $lock = Cache::lock('flow-message-batches:'.$batch->ai_session_id, 195);
        if (! $lock->get()) {
            $this->dispatch($batch, now()->addSecond());

            return;
        }
        try {
            $this->processAi($batch);
        } finally {
            $lock->release();
        }
    }

    private function completeWait(FlowMessageBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $execution = $this->lockExecution($batch->flow_execution_id);
            $batch = FlowMessageBatch::whereKey($batch->id)->lockForUpdate()->first();
            if (! $batch) {
                return;
            }
            $wait = DB::table('flow_waits')->where('id', $batch->flow_wait_id)->lockForUpdate()->first();
            if ($execution?->status !== 'waiting_reply' || $wait?->status !== 'waiting' || $execution->current_node_id !== $wait->node_id) {
                $this->release($batch);

                return;
            }
            if (app(FlowRuntime::class)->isPaused($execution)) {
                app(FlowRuntime::class)->finish($execution, 'interrupted', 'Automação pausada.');

                return;
            }
            if (! $this->ready($batch, $execution)) {
                return;
            }
            $texts = array_map(fn ($entry) => $this->text($entry, $execution), $batch->entries);
            $this->release($batch);
            if (in_array('', $texts, true)) {
                app(FlowRuntime::class)->finish($execution, 'failed', 'Resposta temporária indisponível.');

                return;
            }

            DB::table('contact_custom_field_values')->updateOrInsert(
                ['contact_id' => $execution->contact_id, 'field_id' => $wait->field_id],
                ['workspace_id' => $execution->workspace_id, 'value' => implode("\n", $texts), 'created_at' => now(), 'updated_at' => now()],
            );
            DB::table('flow_waits')->where('id', $wait->id)->update(['status' => 'replied', 'completed_at' => now(), 'updated_at' => now()]);
            $execution->update(['status' => 'running', 'context' => [...$execution->context, 'resume_handle' => 'replied']]);
            ProcessFlowExecution::dispatch($execution->id)->afterCommit();
        }, 3);
    }

    private function processAi(FlowMessageBatch $batch): void
    {
        $batch = DB::transaction(function () use ($batch): ?FlowMessageBatch {
            $execution = $this->lockExecution($batch->flow_execution_id);
            $head = FlowMessageBatch::where('ai_session_id', $batch->ai_session_id)->oldest('id')->lockForUpdate()->first();
            if ($head?->id !== $batch->id) {
                return null;
            }
            $session = AiSession::find($head->ai_session_id);
            if ($session?->status === 'active' && now()->gte($session->expires_at)) {
                return null;
            }
            if (! $session || ! AiSessionState::current($session)) {
                $this->release($head);

                return null;
            }
            if (! $session->remote_conversation_id || (! $head->started_at && isset($execution->context['ai_pending_send']))) {
                $this->dispatch($head, now()->addSeconds(2));

                return null;
            }
            if (! $this->ready($head, $execution)) {
                return null;
            }
            $entries = $head->entries;
            if ($head->kind === 'media' && ! isset($entries[0]['prepare_until'])) {
                $entries[0]['prepare_until'] = now()->addSeconds(180)->toISOString();
            }
            $head->update(['sealed' => true, 'entries' => $entries]);

            return $head;
        }, 3);
        if (! $batch) {
            return;
        }
        $session = AiSession::find($batch->ai_session_id);
        if (! $session || ! AiSessionState::current($session)) {
            $this->finishAi($batch);

            return;
        }

        $entries = array_values(array_filter($batch->entries, fn ($entry) => ! DB::table('ai_session_inputs')
            ->where('ai_session_id', $session->id)->where('provider_message_id', $entry['id'])->exists()));
        $data = collect($session->execution->version->graph['nodes'])->firstWhere('id', $session->node_id)['data'];
        if (isset($data['ai_max_messages'])) {
            $entries = array_slice($entries, 0, max(0, $data['ai_max_messages'] - $session->message_count));
        }
        if ($batch->started_at || ! $entries) {
            if ($batch->started_at && ! isset($session->execution->context['ai_pending_send']) && ! $this->confirmedAiSend($batch, $session)) {
                app(AiSessionService::class)->failById($session->id, 'Processamento da IA interrompido.');
            }
            $this->finishAi($batch);

            return;
        }

        $input = null;
        if ($batch->kind === 'media') {
            $entry = $entries[0];
            $payload = UazapiInbox::find($entry['inbox'])?->payload;
            if ($payload && ! isset($payload['prepared_input']) && ! isset($payload['prepared_error']) && now()->lt(Carbon::parse($entry['prepare_until']))) {
                if (Cache::add('flow-batch-media:'.$batch->id, true, 30)) {
                    PrepareAiInput::dispatch($session->id, $entry['id'], $entry['inbox'], false, $batch->id);
                }

                return;
            }
            $input = $payload && isset($payload['prepared_input']) ? $payload : ['prepared_error' => true];
        } else {
            $texts = array_map(fn ($entry) => $this->text($entry, $session->execution), $entries);
            if (in_array('', $texts, true)) {
                app(AiSessionService::class)->failById($session->id, 'Mensagem temporária indisponível.');
                $this->finishAi($batch);

                return;
            }
            $input = ['prepared_input' => ['video' => false, 'content' => [['type' => 'input_text', 'text' => implode("\n", $texts)]]]];
        }

        if (app(AiSessionService::class)->processBatch($session->id, $batch->id, array_column($entries, 'id'), $input)) {
            $this->finishAi($batch);
        } else {
            $this->dispatch($batch, now()->addSeconds(2));
        }
    }

    private function confirmedAiSend(FlowMessageBatch $batch, AiSession $session): bool
    {
        $suffix = 'batch-'.$batch->id;
        $trackIds = array_map(fn ($value) => 'ai-'.$session->id.'-'.hash('sha256', $value), [
            $suffix, 'unsupported-'.$suffix, 'video-'.$suffix,
        ]);

        return DB::table('flow_events')->where('flow_execution_id', $batch->flow_execution_id)
            ->where('event_type', 'message_confirmed')->whereIn('metadata->track_id', $trackIds)->exists();
    }

    private function finishAi(FlowMessageBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $this->lockExecution($batch->flow_execution_id);
            $current = FlowMessageBatch::whereKey($batch->id)->lockForUpdate()->first();
            if ($current) {
                $this->release($current);
            }
            $next = FlowMessageBatch::where('ai_session_id', $batch->ai_session_id)->oldest('id')->first();
            if ($next) {
                $this->dispatch($next);
            }
        }, 3);
    }

    private function ready(FlowMessageBatch $batch, FlowExecution $execution): bool
    {
        if ($batch->sealed) {
            return true;
        }
        if (now()->lt($batch->due_at)) {
            $this->dispatch($batch);

            return false;
        }
        if ($this->pendingInbound($execution, $batch->due_at, $batch->cutoff_at->subSeconds(3 * $this->interval($execution, $batch->ai_session_id !== null)))) {
            $this->dispatch($batch, now()->addSecond());

            return false;
        }

        return true;
    }

    private function lockExecution(int $executionId): ?FlowExecution
    {
        $contactId = FlowExecution::whereKey($executionId)->value('contact_id');
        Contact::whereKey($contactId)->lockForUpdate()->first();

        return FlowExecution::whereKey($executionId)->lockForUpdate()->first();
    }

    private function text(array $entry, FlowExecution $execution): string
    {
        $message = $entry['selection']
            ? ['messageType' => 'ButtonsResponseMessage', 'buttonOrListid' => $entry['selection']]
            : UazapiInbox::find($entry['inbox'])?->payload['message'] ?? [];
        $button = app(FlowContentButton::class)->resolve($execution->connection, $execution->contact_id, $message);

        return $button['text'] ?? app(FlowInboundProcessor::class)->inboundText($message);
    }

    public function release(FlowMessageBatch $batch): void
    {
        if ($batch->delete()) {
            foreach ($batch->entries as $entry) {
                if ($entry['inbox']) {
                    UazapiInbox::find($entry['inbox'])?->consumed();
                }
            }
        }
    }

    public function cancel(int $executionId): void
    {
        FlowMessageBatch::where('flow_execution_id', $executionId)->lockForUpdate()->get()->each($this->release(...));
    }

    public function recover(): void
    {
        FlowMessageBatch::where('due_at', '<=', now())->eachById(fn ($batch) => $this->dispatch($batch));
        DB::table('flow_waits')->where('status', 'waiting')->where('expires_at', '<=', now())
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('flow_message_batches')->whereColumn('flow_wait_id', 'flow_waits.id'))
            ->eachById(fn ($wait) => ExpireFlowWait::dispatch($wait->id));
    }
}
