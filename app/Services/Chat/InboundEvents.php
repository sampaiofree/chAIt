<?php

namespace App\Services\Chat;

use App\Jobs\Chat\ProcessInboundEvent;
use App\Models\UazapiConnection;
use App\Models\UazapiInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InboundEvents
{
    public function accept(Request $request, UazapiConnection $connection): UazapiInbox
    {
        abort_if(strlen($request->getContent()) > config('chat.max_webhook_bytes'), 413);
        abort_unless($request->isJson(), 415);
        $type = $request->input('EventType');
        abort_unless(in_array($type, ['messages', 'messages_update', 'chats', 'connection'], true), 422);
        // Whitelist the envelope; tokens, server URLs and instance credentials never enter storage.
        $payload = $request->only(['message', 'chat', 'event', 'instance', 'status', 'state', 'type', 'event_id', 'owner']);
        $payload = $this->sanitize($payload);
        foreach (['message', 'chat', 'event', 'instance'] as $field) {
            abort_if(isset($payload[$field]) && ! is_array($payload[$field]), 422);
        }
        $message = $payload['message'] ?? [];
        $messageId = $message['messageid'] ?? data_get($message, 'key.id') ?? $message['id'] ?? null;
        abort_if($type === 'messages' && (! is_string($messageId) || $messageId === '' || strlen($messageId) > 191), 422);
        abort_if($type === 'chats' && empty($payload['chat']), 422);
        if ($type === 'messages_update') {
            $ids = data_get($payload, 'event.MessageIDs');
            abort_unless(is_array($ids) && count($ids) > 0 && collect($ids)->every(fn ($id) => is_string($id) && $id !== '' && strlen($id) <= 191), 422);
            $payload['event']['MessageIDs'] = array_values(array_unique($ids));
            sort($payload['event']['MessageIDs'], SORT_STRING);
        }
        $chat = data_get($payload, 'chat.wa_chatid') ?? data_get($payload, 'chat.chatid') ?? data_get($message, 'chatid') ?? data_get($message, 'key.remoteJid') ?? data_get($payload, 'event.chatid') ?? data_get($payload, 'event.Chat') ?? '';
        abort_unless(is_string($chat) && strlen($chat) <= 191, 422);
        abort_if($type !== 'connection' && $chat === '', 422);
        if ($type === 'messages') {
            $messageChat = $message['chatid'] ?? data_get($message, 'key.remoteJid');
            foreach (['wa_chatid', 'chatid'] as $field) {
                abort_if(isset($payload['chat'][$field]) && $messageChat !== $payload['chat'][$field], 422);
            }
        }
        abort_if($type === 'connection' && ! is_string(data_get($payload, 'instance.status') ?? $payload['status'] ?? null), 422);
        $identity = match ($type) {
            'messages' => $chat.'|'.$messageId,
            'messages_update' => json_encode($this->canonical($payload), JSON_THROW_ON_ERROR),
            // Repeated state snapshots without an event ID can represent a later transition.
            default => is_string($payload['event_id'] ?? null) && $payload['event_id'] !== '' ? $payload['event_id'] : (string) Str::uuid(),
        };
        $hash = hash('sha256', $connection->id.'|'.$type.'|'.$identity);
        $inbox = UazapiInbox::firstOrCreate(['dedupe_hash' => $hash], ['status' => 'pending', 'workspace_id' => $connection->workspace_id, 'uazapi_connection_id' => $connection->id, 'event_type' => $type, 'chat_id' => $chat, 'payload' => $payload, 'expires_at' => now()->addHours(config('chat.temporary_hours'))]);
        if (! $inbox->wasRecentlyCreated) {
            UazapiInbox::whereKey($inbox->id)->increment('delivery_count');
            $inbox->refresh();
        }
        if ($inbox->status === 'failed' && $inbox->expires_at->isFuture()) {
            $inbox->update(['status' => 'pending']);
        }
        if ($inbox->status === 'pending') {
            $this->dispatch($inbox);
        }

        return $inbox;
    }

    public function dispatch(UazapiInbox $inbox): void
    {
        try {
            ProcessInboundEvent::dispatch($inbox->id)->afterCommit();
            $inbox->update(['dispatched_at' => now()]);
        } catch (\Throwable) {
            // Acceptance is already durable. The recovery command dispatches it again.
            Log::warning('Inbound dispatch deferred.', ['inbox_id' => $inbox->id]);
        }
    }

    private function sanitize(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (preg_match('/token|password|secret|base64|thumbnail|mediaKey|fileSha256|fileEncSha256|^BaseUrl$/i', (string) $key)) {
                continue;
            }
            if ($key === 'content' && is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
            if (is_string($value) && str_starts_with($value, 'data:') && ! in_array($key, ['text', 'caption', 'conversation'], true)) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $out;
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
