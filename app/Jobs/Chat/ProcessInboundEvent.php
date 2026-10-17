<?php

namespace App\Jobs\Chat;

use App\Http\Controllers\Workspace\UazapiChatController;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\UazapiInbox;
use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Chat\ChatProjection;
use App\Services\Chat\ChatPublisher;
use App\Services\Contacts\InternationalPhone;
use App\Services\Flows\FlowInboundProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessInboundEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $timeout = 30;

    public function __construct(public int $inboxId)
    {
        $this->onQueue('inbound');
    }

    public function middleware(): array
    {
        return [new WorkspaceCapacity('inbound')];
    }

    public function workspaceId(): ?int
    {
        return UazapiInbox::whereKey($this->inboxId)->value('workspace_id');
    }

    public function handle(ChatProjection $projection, ChatPublisher $publisher, FlowInboundProcessor $flows): void
    {
        $inbox = UazapiInbox::find($this->inboxId);
        if (! $inbox || $inbox->status !== 'pending') {
            return;
        }
        if ($inbox->expires_at->isPast()) {
            $inbox->update(['status' => 'expired', 'payload' => null]);

            return;
        }
        $lock = Cache::lock('chat-inbound:'.$inbox->uazapi_connection_id.':'.hash('sha256', $inbox->chat_id), 40);
        if (! $lock->get()) {
            $this->release(1);

            return;
        }
        try {
            // Enforce acceptance order even when a later job acquires the lock first.
            if (UazapiInbox::where('uazapi_connection_id', $inbox->uazapi_connection_id)->where('chat_id', $inbox->chat_id)->where('id', '<', $inbox->id)->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
                $this->release(1);

                return;
            }
            $publisher->begin();
            DB::transaction(function () use ($inbox, $projection, $publisher, $flows): void {
                $inbox = UazapiInbox::whereKey($inbox->id)->lockForUpdate()->firstOrFail();
                if ($inbox->status !== 'pending') {
                    return;
                }
                $connection = UazapiConnection::findOrFail($inbox->uazapi_connection_id);
                $payload = $inbox->payload ?? [];
                if ($inbox->event_type === 'connection') {
                    $raw = strtolower((string) (data_get($payload, 'instance.status') ?? (is_string($payload['status'] ?? null) ? $payload['status'] : '')));
                    if (in_array($raw, ['connected', 'open', 'online', 'disconnected', 'close', 'offline', 'connecting'], true)) {
                        $connected = in_array($raw, ['connected', 'open', 'online'], true);
                        $phone = app(InternationalPhone::class)->normalize($payload['owner'] ?? data_get($payload, 'instance.phone'));
                        $connection->update(['status' => $connected ? 'connected' : ($raw === 'connecting' ? 'connecting' : 'disconnected'), 'phone' => $phone ?? $connection->phone, 'connected_at' => $connected ? ($connection->connected_at ?? now()) : null, 'provider_checked_at' => now()]);
                        $publisher->send($connection->workspace_id, 'connection.changed', ['status' => $connection->status, 'phone' => $connection->phone, 'connected_at' => $connection->connected_at, 'last_checked_at' => $connection->provider_checked_at]);
                    }
                } elseif ($inbox->event_type === 'messages' || $inbox->event_type === 'chats') {
                    $message = $inbox->event_type === 'messages' ? ($payload['message'] ?? []) : null;
                    $thread = $projection->sync($connection, $payload['chat'] ?? [], $message);
                    if ($thread && $message) {
                        $serialized = app(UazapiChatController::class)->transientMessage($message, $connection->workspace, $thread);
                        $publisher->send($connection->workspace_id, 'message.received', $serialized, $thread->id);
                        $flows->handle($connection, $thread, $message, $inbox->id);
                    }
                } elseif ($inbox->event_type === 'messages_update') {
                    $ids = data_get($payload, 'event.MessageIDs', []);
                    $state = strtolower((string) ($payload['state'] ?? data_get($payload, 'event.Type') ?? ''));
                    $thread = Thread::where('uazapi_connection_id', $connection->id)->where('external_chat_id', $inbox->chat_id)->first();
                    if ($thread && is_array($ids) && in_array($state, ['sent', 'delivered', 'read', 'played', 'failed'], true)) {
                        $publisher->send($connection->workspace_id, 'message.status', ['ids' => array_values(array_filter($ids, 'is_string')), 'status' => $state], $thread->id);
                    }
                }
                $connection->update(['chat_last_webhook_at' => now()]);
                $inbox->refresh();
                $inbox->update(['status' => 'processed', 'payload' => $inbox->consumers > 0 ? $inbox->payload : null]);
            }, 3);
            // Publish only after durable domain work and downstream job dispatches have committed.
            $lock->release();
            $publisher->flush();
        } catch (\Throwable $error) {
            // Do not let queue exceptions capture decrypted arguments or provider content.
            $inbox->update(['status' => 'failed']);
            Log::error('Inbound processing failed.', ['inbox_id' => $inbox->id, 'exception_type' => $error::class]);
        } finally {
            $publisher->discard();
            $lock->release();
        }
    }
}
