<?php

namespace App\Services\Chat;

use App\Models\ChatOperation;
use App\Models\UazapiConnection;
use App\Models\UazapiInbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ChatMaintenance
{
    public function recover(): void
    {
        UazapiInbox::where('status', 'pending')->where('expires_at', '>', now())->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(2)))->eachById(fn ($inbox) => app(InboundEvents::class)->dispatch($inbox));
        ChatOperation::where('status', 'pending')->where('expires_at', '>', now())->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(4)))->eachById(fn ($operation) => app(ChatOperations::class)->dispatch($operation));
        ChatOperation::where('status', 'running')->where('updated_at', '<', now()->subMinutes(4))->eachById(function ($operation): void {
            $reading = in_array($operation->kind, ['history', 'avatar', 'media', 'read'], true);
            $operation->update(['status' => $reading ? 'pending' : 'confirmation_pending']);
            if ($reading) {
                app(ChatOperations::class)->dispatch($operation);
            } elseif ($operation->user_id) {
                app(ChatPublisher::class)->send($operation->workspace_id, 'operation.finished', ['operation_id' => $operation->id, 'status' => 'confirmation_pending', 'result' => ['data' => ['status' => 'confirmation_pending', 'track_id' => $operation->track_id]]], $operation->thread_id, $operation->user_id);
            }
        });
    }

    public function prune(): void
    {
        UazapiInbox::whereNotNull('payload')->where('expires_at', '<=', now())->eachById(function ($inbox): void {
            $inbox->removeFiles();
            $inbox->update(['payload' => null, 'status' => 'expired', 'consumers' => 0]);
        });
        ChatOperation::whereNotNull('payload')->where('expires_at', '<=', now())->eachById(function ($operation): void {
            if ($path = data_get($operation->payload, 'file_path')) {
                Storage::disk('local')->delete($path);
            }
            $operation->update(['payload' => null, 'status' => in_array($operation->status, ['pending', 'running'], true) ? 'expired' : $operation->status]);
        });
        // Orphaned uploads (e.g. a crash before operation persistence) have the same retention.
        foreach (Storage::disk('local')->files('chat-temporary') as $path) {
            if (Storage::disk('local')->lastModified($path) <= now()->subHours(config('chat.temporary_hours'))->timestamp) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function metrics(): array
    {
        return ['provider' => UazapiConnection::query()->limit(1000)->get()->map(fn ($connection) => ['connection_id' => $connection->id, 'limited_last_hour' => (int) Cache::get('chat-provider:connection:'.$connection->id.':limited', 0), 'last_latency_ms' => Cache::get('chat-provider:connection:'.$connection->id.':latency_ms'), 'activated_at' => $connection->activated_at, 'last_webhook_at' => $connection->chat_last_webhook_at])->all(), 'inbound_pending' => UazapiInbox::where('status', 'pending')->count(), 'inbound_oldest_at' => UazapiInbox::where('status', 'pending')->min('created_at'), 'inbound_failed' => UazapiInbox::where('status', 'failed')->count(), 'operations_pending' => ChatOperation::whereIn('status', ['pending', 'running'])->count(), 'operations_oldest_at' => ChatOperation::whereIn('status', ['pending', 'running'])->min('created_at'), 'temporary_expiring_hour' => UazapiInbox::whereNotNull('payload')->where('expires_at', '<=', now()->addHour())->count()];
    }
}
