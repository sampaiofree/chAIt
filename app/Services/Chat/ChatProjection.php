<?php

namespace App\Services\Chat;

use App\Models\ContactChannel;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Services\Contacts\ContactIdentity;
use App\Services\Contacts\InternationalPhone;
use App\Services\Flows\FlowExecutionManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatProjection
{
    public function timestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return is_numeric($value) ? Carbon::createFromTimestampUTC((float) $value > 100000000000 ? (float) $value / 1000 : (float) $value) : Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public function sync(UazapiConnection $connection, array $chat, ?array $message = null, bool $allowCreate = false): ?Thread
    {
        $external = (string) ($chat['wa_chatid'] ?? $chat['chatid'] ?? $chat['jid'] ?? $chat['id'] ?? $message['chatid'] ?? data_get($message, 'key.remoteJid') ?? '');
        if ($external === '' || strlen($external) > 191 || str_contains($external, '@g.us') || ($chat['wa_isGroup'] ?? false) || ($message['isGroup'] ?? false)) {
            return null;
        }
        if ($allowCreate && ! preg_match('/^[0-9]+@s\.whatsapp\.net$/D', $external)) {
            return null;
        }

        return DB::transaction(function () use ($connection, $chat, $message, $external, $allowCreate) {
            $thread = Thread::where('workspace_id', $connection->workspace_id)->where('uazapi_connection_id', $connection->id)->where('external_chat_id', $external)->first();
            if (! $thread && ! $allowCreate) {
                return null;
            }
            if ($thread) {
                $contact = $thread->contact()->lockForUpdate()->firstOrFail();
                $thread = Thread::whereKey($thread->id)->lockForUpdate()->firstOrFail();
            } else {
                $channel = ContactChannel::where('workspace_id', $connection->workspace_id)->where('type', 'whatsapp')->where('external_id', $external)->first();
                if (! $channel) {
                    preg_match('/^([0-9]+)@s\.whatsapp\.net$/D', $external, $matches);
                    $phone = app(InternationalPhone::class)->normalize($matches[1]);
                    if ($phone === null) {
                        return null;
                    }
                    $contact = app(ContactIdentity::class)->locked($connection->workspace_id, $phone);
                    $channel = ContactChannel::firstOrCreate(['workspace_id' => $connection->workspace_id, 'type' => 'whatsapp', 'external_id' => $external], ['contact_id' => $contact->id]);
                }
                $contact = $channel->contact()->lockForUpdate()->firstOrFail();
                $thread = Thread::firstOrCreate(['workspace_id' => $connection->workspace_id, 'uazapi_connection_id' => $connection->id, 'external_chat_id' => $external], ['contact_id' => $contact->id, 'channel' => 'whatsapp', 'status' => 'bot']);
                $thread = Thread::whereKey($thread->id)->lockForUpdate()->firstOrFail();
            }
            $name = $chat['name'] ?? $chat['wa_contactName'] ?? $chat['wa_name'] ?? $message['senderName'] ?? null;
            if (! $contact->display_name && is_string($name)) {
                $contact->display_name = mb_substr($name, 0, 255);
            }
            $image = $chat['imagePreview'] ?? $chat['imagePreviewUrl'] ?? $chat['image'] ?? null;
            if (is_string($image) && filter_var($image, FILTER_VALIDATE_URL)) {
                $contact->profile = array_merge($contact->profile ?? [], ['imagePreview' => $image]);
            }
            if ($contact->isDirty()) {
                $contact->save();
            }
            $time = $this->timestamp($message['messageTimestamp'] ?? $message['timestamp'] ?? $chat['wa_lastMsgTimestamp'] ?? $chat['updatedAt'] ?? null);
            $fromMe = (bool) ($message['fromMe'] ?? data_get($message, 'key.fromMe') ?? false);
            // A snapshot without a timestamp must not overwrite a known message.
            $newer = $message && $time && (! $thread->last_message_at || $time->gte($thread->last_message_at));
            if ($newer) {
                $type = strtolower((string) ($message['messageType'] ?? $message['type'] ?? $chat['wa_lastMessageType'] ?? 'text'));
                $content = $message['content'] ?? null;
                if (is_string($content)) {
                    $content = json_decode($content, true) ?: $content;
                }
                $text = $message['text'] ?? data_get($content, 'text') ?? data_get($content, 'conversation') ?? (is_string($content) ? $content : null) ?? $chat['wa_lastMessageTextVote'] ?? null;
                $textual = in_array($type, ['text', 'conversation', 'extendedtextmessage', 'extended_text_message'], true);
                $thread->forceFill([
                    'last_message_at' => $time,
                    'last_message_id' => mb_substr((string) ($message['id'] ?? $message['messageid'] ?? ''), 0, 191) ?: $thread->last_message_id,
                    'last_message_type' => mb_substr($type, 0, 64),
                    'last_message_direction' => $message ? ($fromMe ? 'outbound' : 'inbound') : $thread->last_message_direction,
                    'last_message_preview' => $textual && is_string($text) ? mb_substr($text, 0, config('chat.preview_characters')) : null,
                ]);
            }
            if ($message) {
                $field = $fromMe ? 'last_outbound_at' : 'last_inbound_at';
                if ($time && (! $thread->$field || $time->gt($thread->$field))) {
                    $thread->$field = $time;
                }
                if (! $fromMe) {
                    $thread->unread_count++;
                    $thread->unread_version++;
                }
            } elseif ($time && (! $thread->last_message_at || $time->gte($thread->last_message_at)) && isset($chat['wa_unreadCount'])) {
                $count = max(0, (int) $chat['wa_unreadCount']);
                if ($count !== $thread->unread_count) {
                    $thread->unread_count = $count;
                    $thread->unread_version++;
                }
            }
            $thread->chat_revision++;
            $thread->save();

            return $thread;
        }, 3);
    }

    /** Bounded queries regardless of page size. */
    public function serialize(Collection $threads, ?int $detailId = null): Collection
    {
        $threads = new \Illuminate\Database\Eloquent\Collection($threads->all());
        $threads->loadMissing(['contact.tags', 'contact.customFieldValues.field:id,name', 'assignedTo']);
        if ($detailId && ($detail = $threads->firstWhere('id', $detailId))) {
            $detail->loadMissing('notes.user');
        }
        $contacts = $threads->pluck('contact_id')->unique();
        $connections = $threads->pluck('uazapi_connection_id')->filter()->unique();
        $pauses = DB::table('automation_pauses')->whereIn('uazapi_connection_id', $connections)->whereIn('contact_id', $contacts)->whereNull('released_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get()->groupBy(fn ($p) => $p->uazapi_connection_id.':'.$p->contact_id);
        $executions = DB::table('flow_executions')->whereIn('uazapi_connection_id', $connections)->whereIn('contact_id', $contacts)->whereIn('status', FlowExecutionManager::ACTIVE)->orderByDesc('id')->get()->groupBy(fn ($e) => $e->uazapi_connection_id.':'.$e->contact_id);

        return $threads->map(function (Thread $thread) use ($pauses, $executions, $detailId) {
            $key = $thread->uazapi_connection_id.':'.$thread->contact_id;
            $rows = $pauses->get($key, collect());
            $reasons = $rows->pluck('reason')->unique()->values();
            $human = in_array($thread->status, ['open', 'in_progress'], true) && $thread->human_opened_at;
            if ($human && ! $reasons->contains('human')) {
                $reasons->push('human');
            }
            $manual = $rows->where('reason', 'manual');
            $hasManual = $manual->isNotEmpty();
            $data = [
                'id' => $thread->id, 'status' => $thread->status, 'revision' => (int) $thread->chat_revision,
                'last_message_at' => $thread->last_message_at, 'last_message_id' => $thread->last_message_id,
                'last_message_type' => $thread->last_message_type, 'last_message_direction' => $thread->last_message_direction,
                'last_message_preview' => $thread->last_message_preview,
                'unread_count' => $thread->unread_count, 'unread_version' => (int) $thread->unread_version,
                'assigned_to' => $thread->assignedTo?->only(['id', 'name']), 'last_assigned_to_user_id' => $thread->last_assigned_to_user_id,
                'contact' => array_merge($thread->contact->only(['id', 'display_name', 'phone', 'email', 'cpf']), ['avatar_url' => data_get($thread->contact->profile, 'imagePreview')]),
                'tags' => $thread->contact->tags->map->only(['id', 'name'])->values(),
                'custom_fields' => $thread->contact->customFieldValues->map(fn ($v) => ['id' => $v->field_id, 'name' => $v->field?->name, 'value' => $v->value])->values(),
                'automations_paused' => $hasManual,
                'automation' => ['state' => ! $thread->uazapi_connection_id ? 'unavailable' : ($reasons->isEmpty() ? 'allowed' : 'paused'), 'pause_reasons' => $reasons->all(), 'execution_status' => $executions->get($key)?->first()?->status, 'can_resume' => $hasManual && ! $human, 'can_pause' => ! $hasManual && (bool) $thread->uazapi_connection_id, 'expires_at' => $hasManual && ! $manual->contains(fn ($p) => $p->expires_at === null) ? Carbon::parse($manual->max('expires_at'))->toIso8601String() : null],
            ];
            if ($thread->id === $detailId) {
                $data['notes'] = $thread->notes->map(fn ($n) => ['id' => $n->id, 'body' => $n->body, 'author' => $n->user?->name, 'created_at' => $n->created_at])->values();
            }

            return $data;
        });
    }
}
