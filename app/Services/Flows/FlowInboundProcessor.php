<?php

namespace App\Services\Flows;

use App\Jobs\ProcessAiTurn;
use App\Jobs\ProcessFlowExecution;
use App\Models\AiSession;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\UazapiInbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowInboundProcessor
{
    public function __construct(private FlowRuntime $runtime) {}

    public function handle(UazapiConnection $connection, Thread $thread, array $message, ?int $inboxId = null): void
    {
        if ((bool) (data_get($message, 'fromMe') ?? data_get($message, 'from_me') ?? data_get($message, 'key.fromMe') ?? false)) {
            return;
        }
        if ((bool) data_get($message, 'isGroup', false) || str_contains((string) $thread->external_chat_id, '@g.us')) {
            return;
        }
        $lock = Cache::lock("flow-inbound:{$connection->id}:{$thread->contact_id}", 20);
        $lock->block(3, function () use ($connection, $thread, $message, $inboxId): void {
            DB::transaction(function () use ($connection, $thread, $message, $inboxId): void {
                $contact = $thread->contact()->lockForUpdate()->first();
                $thread->refresh();
                $buttonReply = app(FlowContentButton::class)->resolve($connection, $contact->id, $message);
                if ($buttonReply) {
                    $id = $message['messageid'] ?? $message['id'] ?? data_get($message, 'key.id');
                    if (! is_string($id) || $id === '' || ! DB::table('uazapi_webhook_receipts')->insertOrIgnore([
                        'uazapi_connection_id' => $connection->id, 'event_type' => 'content_reply',
                        'event_id' => $id, 'dedupe_hash' => hash('sha256', $connection->id.'|content_reply|'.$id),
                        'created_at' => now(), 'updated_at' => now(),
                    ])) {
                        return;
                    }
                }
                $text = $buttonReply['text'] ?? $this->inboundText($message);
                $now = now();
                $first = ! DB::table('flow_first_contacts')->where('uazapi_connection_id', $connection->id)->where('contact_id', $contact->id)->exists();
                if ($first) {
                    DB::table('flow_first_contacts')->insertOrIgnore([
                        'workspace_id' => $connection->workspace_id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id,
                        'first_inbound_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $this->releaseExpiredPauses($connection, $contact->id);
                if ($this->paused($connection, $contact->id, $thread)) {
                    return;
                }

                $active = FlowExecution::query()->where('contact_id', $contact->id)->where('uazapi_connection_id', $connection->id)
                    ->whereIn('status', ['running', 'waiting_reply', 'waiting_ai', 'waiting_menu'])->orderByDesc('id')->lockForUpdate()->first();
                $settings = DB::table('flow_trigger_settings')->where('uazapi_connection_id', $connection->id)->first();
                $rules = DB::table('flow_keyword_triggers')->where('uazapi_connection_id', $connection->id)->orderBy('position')->get();
                $eligible = $rules->filter(function ($rule) use ($text, $active): bool {
                    if ($active && ! $rule->interrupt_active) {
                        return false;
                    }

                    return $text !== '' && $this->matches($text, $rule->term, $rule->operator);
                })->first();

                if ($eligible) {
                    if ($active) {
                        $this->interrupt($active, 'Interrompido por palavra-chave.');
                    }
                    $this->startFlow((int) $eligible->flow_id, $contact->id, $thread, $connection, 'keyword');

                    return;
                }
                if ($active) {
                    if ($active->status === 'running' && ! isset($active->context['resume_handle']) && $buttonReply && $buttonReply['execution_id'] === $active->id
                        && $buttonReply['wait_node_id'] && in_array($active->current_node_id, [$buttonReply['node_id'], $buttonReply['wait_node_id']], true)) {
                        $context = $active->context;
                        $context['early_button_reply'] ??= ['selection' => $buttonReply['selection'], 'wait_node_id' => $buttonReply['wait_node_id']];
                        $active->update(['context' => $context]);
                    }
                    if ($active->status === 'waiting_menu') {
                        app(FlowMenuRuntime::class)->receive($active, $message);
                    }
                    if ($active->status === 'waiting_ai') {
                        $session = AiSession::where('flow_execution_id', $active->id)->where('status', 'active')->latest('id')->first();
                        $messageId = (string) (data_get($message, 'id') ?? data_get($message, 'messageid') ?? data_get($message, 'key.id') ?? '');
                        if ($session && $messageId !== '') {
                            if ($inboxId) {
                                UazapiInbox::whereKey($inboxId)->increment('consumers');
                            }
                            ProcessAiTurn::dispatch($session->id, $messageId, $inboxId)->afterCommit();
                        }
                    }
                    if ($active->status === 'waiting_reply' && $text !== '') {
                        $this->answerWait($active, $text);
                    }

                    return;
                }

                $flowId = $first ? ($settings->welcome_flow_id ?? $settings->default_flow_id ?? null) : ($settings->default_flow_id ?? null);
                if ($flowId) {
                    $this->startFlow((int) $flowId, $contact->id, $thread, $connection, $first && $settings->welcome_flow_id ? 'welcome' : 'default');
                }
            });
        });
    }

    /** Called inside the contact/context transaction that activates the following wait. */
    public function consumeEarlyButtonReply(FlowExecution $execution, string $nodeId): void
    {
        $early = $execution->context['early_button_reply'] ?? null;
        if (! $early || $early['wait_node_id'] !== $nodeId) {
            return;
        }
        $context = $execution->context;
        unset($context['early_button_reply']);
        $execution->update(['context' => $context]);
        $reply = app(FlowContentButton::class)->resolve($execution->connection, $execution->contact_id,
            ['messageType' => 'ButtonsResponseMessage', 'buttonOrListid' => $early['selection']]);
        if ($reply && $reply['execution_id'] === $execution->id) {
            $this->answerWait($execution, $reply['text']);
        }
    }

    private function inboundText(array $message): string
    {
        $type = strtolower((string) (data_get($message, 'messageType') ?? data_get($message, 'type') ?? 'text'));
        if (! in_array($type, ['text', 'conversation', 'extendedtextmessage', 'extended_text_message'], true)) {
            return '';
        }
        $content = data_get($message, 'content');
        if (is_string($content)) {
            $content = json_decode($content, true) ?: $content;
        }
        $text = data_get($message, 'text') ?? data_get($content, 'text') ?? data_get($content, 'conversation') ?? (is_string($content) ? $content : '');

        return is_string($text) ? trim($text) : '';
    }

    private function matches(string $text, string $term, string $operator): bool
    {
        $normalize = fn (string $value): string => Str::lower(trim(Str::ascii($value)));
        $text = $normalize($text);
        $term = $normalize($term);

        return match ($operator) {
            'exact' => $text === $term, 'prefix' => str_starts_with($text, $term), 'contains' => str_contains($text, $term), default => false,
        };
    }

    private function answerWait(FlowExecution $execution, string $text): void
    {
        $wait = DB::table('flow_waits')->where('flow_execution_id', $execution->id)->where('status', 'waiting')->lockForUpdate()->first();
        if (! $wait || now()->gte($wait->expires_at)) {
            return;
        }
        DB::table('contact_custom_field_values')->updateOrInsert(
            ['contact_id' => $execution->contact_id, 'field_id' => $wait->field_id],
            ['workspace_id' => $execution->workspace_id, 'value' => $text, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('flow_waits')->where('id', $wait->id)->where('status', 'waiting')->update(['status' => 'replied', 'completed_at' => now(), 'updated_at' => now()]);
        $context = $execution->context ?? [];
        $context['resume_handle'] = 'replied';
        $execution->forceFill(['status' => 'running', 'context' => $context])->save();
        ProcessFlowExecution::dispatch($execution->id)->afterCommit();
    }

    private function startFlow(int $flowId, int $contactId, Thread $thread, UazapiConnection $connection, string $source): void
    {
        $flow = Flow::query()->where('workspace_id', $connection->workspace_id)->where('is_active', true)->whereKey($flowId)->first();
        if ($flow?->publishedVersion) {
            $this->runtime->start($flow->publishedVersion, $contactId, $thread->id, $connection, $source);
        }
    }

    private function interrupt(FlowExecution $execution, string $reason): void
    {
        $this->runtime->finish($execution, 'interrupted', $reason);
    }

    private function releaseExpiredPauses(UazapiConnection $connection, int $contactId): void
    {
        DB::table('automation_pauses')->where('uazapi_connection_id', $connection->id)->where('contact_id', $contactId)->whereNull('released_at')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->update(['released_at' => now(), 'updated_at' => now()]);
    }

    private function paused(UazapiConnection $connection, int $contactId, Thread $thread): bool
    {
        $manual = DB::table('automation_pauses')->where('uazapi_connection_id', $connection->id)->where('contact_id', $contactId)->whereNull('released_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();

        return $manual || (in_array($thread->status, ['open', 'in_progress'], true) && $thread->human_opened_at !== null);
    }
}
