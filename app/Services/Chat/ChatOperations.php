<?php

namespace App\Services\Chat;

use App\Jobs\Chat\ProcessChatOperation;
use App\Jobs\Chat\ProcessConnectionOperation;
use App\Models\ChatOperation;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatOperations
{
    public function create(Thread $thread, int $userId, string $kind, array $payload): ChatOperation
    {
        $track = $payload['track_id'] ?? (string) Str::uuid();
        if (in_array($kind, ['send', 'send_file'], true)) {
            $payload['track_id'] = $track;
        }
        $operation = ChatOperation::firstOrCreate(['uazapi_connection_id' => $thread->uazapi_connection_id, 'track_id' => $track, 'kind' => $kind], [
            'status' => 'pending', 'workspace_id' => $thread->workspace_id, 'user_id' => $userId, 'thread_id' => $thread->id, 'payload' => $payload, 'expires_at' => now()->addHours(config('chat.temporary_hours')),
        ]);
        abort_unless($operation->user_id === $userId, 409);
        if (! $operation->wasRecentlyCreated && isset($payload['file_path']) && data_get($operation->payload, 'file_path') !== $payload['file_path']) {
            Storage::disk('local')->delete($payload['file_path']);
        }
        $this->dispatch($operation);

        return $operation;
    }

    public function transport(UazapiConnection $connection, string $kind, array $payload): array
    {
        $executionId = app(TransportContext::class)->executionId;
        if (! $executionId) {
            throw new \RuntimeException('Transport requires an execution context.');
        }
        $track = $payload['track_id'] ?? (string) Str::uuid();
        $execution = FlowExecution::findOrFail($executionId);
        $operation = ChatOperation::firstOrCreate(['uazapi_connection_id' => $connection->id, 'track_id' => $track, 'kind' => 'transport'], [
            'status' => 'pending', 'workspace_id' => $connection->workspace_id, 'thread_id' => $execution->thread_id, 'flow_execution_id' => $executionId, 'payload' => ['transport' => $kind, ...$payload], 'expires_at' => now()->addHours(config('chat.temporary_hours')),
        ]);
        $this->dispatch($operation);

        return ['status' => 'queued', 'track_id' => $track];
    }

    public function dispatch(ChatOperation $operation): void
    {
        if ($operation->status !== 'pending') {
            return;
        }
        try {
            if (in_array($operation->kind, ['connect', 'diagnose'], true)) {
                ProcessConnectionOperation::dispatch($operation->id)->afterCommit();
                $operation->update(['dispatched_at' => now()]);

                return;
            }
            ProcessChatOperation::dispatch($operation->id)->onQueue($operation->kind === 'avatar' ? 'default' : (in_array($operation->kind, ['media', 'send_file'], true) || data_get($operation->payload, 'transport') === 'asset' ? 'media' : 'automations'))->afterCommit();
            $operation->update(['dispatched_at' => now()]);
        } catch (\Throwable) {
            Log::warning('Chat operation dispatch deferred.', ['operation_id' => $operation->id]);
        }
    }
}
