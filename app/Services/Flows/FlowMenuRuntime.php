<?php

namespace App\Services\Flows;

use App\Jobs\ExpireFlowMenu;
use App\Jobs\ProcessFlowExecution;
use App\Jobs\ProcessFlowMenu;
use App\Models\FlowExecution;
use App\Models\FlowMenuWait;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiRejectedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FlowMenuRuntime
{
    public function __construct(private FlowExecutionManager $manager, private FlowMenu $menus, private UazapiClient $client) {}

    public function start(FlowExecution $execution, array $node): void
    {
        $this->manager->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $node): void {
            $execution->refresh();
            if ($execution->status !== 'running' || $execution->current_node_id !== $node['id']) {
                return;
            }
            if ($this->menus->issues($node['data'], $execution->workspace) !== []) {
                app(FlowRuntime::class)->finish($execution, 'failed', 'Configuração ou homologação inválida no Menu.');

                return;
            }
            $wait = FlowMenuWait::firstOrCreate(['flow_execution_id' => $execution->id, 'node_id' => $node['id']], [
                'workspace_id' => $execution->workspace_id, 'selection_token' => (string) Str::uuid(),
                'effective_format' => $node['data']['menu_format'], 'retry_queue' => [],
            ]);
            $execution->update(['status' => 'waiting_menu']);
            ProcessFlowMenu::dispatch($wait->id)->afterCommit();
        });
    }

    private function locked(int $waitId, callable $callback): mixed
    {
        $executionId = FlowMenuWait::whereKey($waitId)->value('flow_execution_id');
        $execution = FlowExecution::find($executionId);
        if (! $execution) {
            return null;
        }

        return $this->manager->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $waitId, $callback) {
            $execution->refresh();
            $wait = FlowMenuWait::whereKey($waitId)->lockForUpdate()->first();
            if (! $wait || $execution->status !== 'waiting_menu' || $execution->current_node_id !== $wait->node_id || ! in_array($wait->status, ['sending', 'waiting'], true)) {
                return null;
            }
            if (! app(FlowRuntime::class)->canSend($execution)) {
                app(FlowRuntime::class)->finish($execution, 'interrupted', 'Automação pausada.');

                return null;
            }
            if ($wait->expires_at && now()->gte($wait->expires_at)) {
                $this->complete($execution, $wait, 'timed_out', 'continue');

                return null;
            }

            return $callback($execution, $wait);
        });
    }

    private function data(FlowExecution $execution, FlowMenuWait $wait): array
    {
        return collect($execution->version->graph['nodes'])->firstWhere('id', $wait->node_id)['data'];
    }

    public function receive(FlowExecution $execution, array $message): void
    {
        $waitId = FlowMenuWait::where('flow_execution_id', $execution->id)->where('node_id', $execution->current_node_id)->value('id');
        if (! $waitId) {
            return;
        }
        $this->locked($waitId, function (FlowExecution $execution, FlowMenuWait $wait) use ($message): void {
            if ($wait->status === 'sending' && empty($wait->transport['reserved_at'])) {
                return;
            }
            $type = strtolower((string) ($message['messageType'] ?? $message['type'] ?? 'text'));
            if (($message['fromMe'] ?? $message['from_me'] ?? data_get($message, 'key.fromMe', false)) || ($message['wasSentByApi'] ?? false)
                || ($message['isGroup'] ?? false) || in_array($type, ['reaction', 'reactionmessage', 'protocolmessage', 'call', 'pollupdate', 'messagestub'], true)
                || filled($message['reaction'] ?? null)) {
                return;
            }
            $id = $message['messageid'] ?? $message['id'] ?? data_get($message, 'key.id');
            if (! is_string($id) || $id === '') {
                return;
            }
            $timestamp = $message['messageTimestamp'] ?? $message['timestamp'] ?? null;
            if (is_numeric($timestamp)) {
                $seconds = (float) $timestamp > 100000000000 ? (float) $timestamp / 1000 : (float) $timestamp;
                $sentAt = $wait->created_at;
                if ($seconds < $sentAt->timestamp) {
                    return;
                }
            }
            $selection = $message['buttonOrListid'] ?? null;
            // Tokens from previous waits are operationally stale, not mistakes by the lead.
            if (is_string($selection) && str_starts_with($selection, 'menu:') && ! str_starts_with($selection, 'menu:'.$wait->selection_token.':')) {
                return;
            }
            if (! DB::table('flow_menu_receipts')->insertOrIgnore(['flow_menu_wait_id' => $wait->id, 'message_hash' => hash('sha256', $id), 'created_at' => now()])) {
                return;
            }
            $data = $this->data($execution, $wait);
            $optionId = null;
            $format = $wait->status === 'sending' ? $wait->transport['mode'] : $wait->effective_format;
            if ($format === 'numeric' && is_string($selection) && $selection !== '') {
                return; // Interactive choices cannot answer a numeric fallback.
            }
            if ($format === 'numeric') {
                $content = $message['content'] ?? null;
                if (is_string($content)) {
                    $content = json_decode($content, true) ?: $content;
                }
                $text = $message['text'] ?? data_get($content, 'text') ?? data_get($content, 'conversation') ?? (is_string($content) ? $content : '');
                if (in_array($type, ['text', 'conversation', 'extendedtextmessage', 'extended_text_message'], true) && is_string($text) && preg_match('/^[1-9][0-9]*$/D', trim($text))) {
                    foreach ($data['options'] as $index => $option) {
                        if (trim($text) === (string) ($index + 1)) {
                            $optionId = $option['id'];
                            break;
                        }
                    }
                }
            } elseif (is_string($selection) && $selection !== ''
                && $type === ($format === 'button' ? 'buttonsresponsemessage' : 'listresponsemessage')) {
                // Only the provider's explicit selection field is evidence of an interactive choice.
                foreach ($data['options'] as $option) {
                    if (hash_equals($this->selectionId($wait, $option['id']), $selection)) {
                        $optionId = $option['id'];
                        break;
                    }
                }
            }
            if ($wait->status === 'sending') {
                // A webhook can beat the HTTP acknowledgement. Keep only the interpreted
                // option/error metadata, and consume it once transport is confirmed.
                $transport = $wait->transport;
                $early = $transport['early_replies'] ?? [];
                if (count($early) < 10) {
                    $transport['early_replies'] = [...$early, ['option_id' => $optionId]];
                    $wait->update(['transport' => $transport]);
                }

                return;
            }
            $this->consumeChoice($execution, $wait, $data, $optionId);
        });
    }

    private function consumeChoice(FlowExecution $execution, FlowMenuWait $wait, array $data, ?string $optionId): void
    {
        if ($optionId !== null) {
            $wait->selected_option_id = $optionId;
            $this->complete($execution, $wait, 'selected', 'option:'.$optionId);

            return;
        }
        $wait->invalid_count++;
        if ($wait->invalid_count >= $data['max_invalid_replies']) {
            $this->complete($execution, $wait, 'invalid_limit', 'continue');

            return;
        }
        $wait->retry_queue = [...$wait->retry_queue, $wait->invalid_count];
        $wait->save();
        ProcessFlowMenu::dispatch($wait->id)->afterCommit();
    }

    public function selectionId(FlowMenuWait $wait, string $optionId): string
    {
        return 'menu:'.$wait->selection_token.':'.$optionId;
    }

    private function complete(FlowExecution $execution, FlowMenuWait $wait, string $status, string $handle): void
    {
        $wait->fill(['status' => $status, 'completed_at' => now(), 'transport' => null, 'retry_queue' => []])->save();
        $context = $execution->context ?? [];
        $context['resume_handle'] = $handle;
        $execution->update(['status' => 'running', 'context' => $context]);
        $this->event($execution, $wait, $status, ['handle' => $handle, 'invalid_count' => $wait->invalid_count]);
        ProcessFlowExecution::dispatch($execution->id)->afterCommit();
    }

    public function expire(int $waitId): void
    {
        // locked() consumes an expired wait atomically, and otherwise leaves it unchanged.
        $this->locked($waitId, fn () => null);
    }

    public function process(int $waitId): void
    {
        $lock = Cache::lock('flow-menu:'.$waitId, 110);
        if (! $lock->get()) {
            return;
        }
        try {
            $this->processLocked($waitId);
        } finally {
            $lock->release();
        }
    }

    private function processLocked(int $waitId): void
    {
        $reservation = $this->locked($waitId, function (FlowExecution $execution, FlowMenuWait $wait): ?array {
            if ($wait->transport) {
                $pending = $wait->transport;
                $send = $pending['reserved_at'] === null;
                if (! $send && isset($pending['next_check_at']) && now()->lt(Carbon::parse($pending['next_check_at']))) {
                    return null;
                }
                if ($send) {
                    $pending['reserved_at'] = now()->toIso8601String();
                    $wait->update(['transport' => $pending]);
                }

                return [$execution, $wait, $send];
            }
            if ($wait->status === 'waiting' && ! $wait->retry_queue) {
                return null;
            }
            $kind = $wait->status === 'sending' ? 'menu' : 'error';
            $wait->transport = ['track_id' => (string) Str::uuid(), 'kind' => $kind, 'mode' => $wait->effective_format, 'attempts' => 0, 'reserved_at' => now()->toIso8601String()];
            $wait->save();

            return [$execution, $wait, true];
        });
        if (! $reservation) {
            return;
        }
        [$execution, $wait, $send] = $reservation;
        $transport = $wait->transport;
        $message = null;
        if ($send) {
            // Recheck after reserving and before transport. No external call occurs in a DB transaction.
            if (! $this->locked($waitId, fn () => true)) {
                return;
            }
            $data = $this->data($execution, $wait);
            try {
                $to = $execution->thread->external_chat_id ?: $execution->contact->phone;
                if ($transport['kind'] === 'error' || $transport['mode'] === 'numeric') {
                    $message = $this->client->sendText($execution->connection, $to, $transport['kind'] === 'error' ? $data['invalid_reply_text'] : $this->menus->numericText($data), $transport['track_id']);
                } else {
                    if (! $this->menus->profile($execution->connection, $transport['mode'])) {
                        $this->fail($waitId, 'O formato interativo perdeu sua homologação.');

                        return;
                    }
                    $message = $this->client->sendMenu($execution->connection, $to, $transport['mode'], $data['question'], array_map(fn ($option) => $option['label'].'|'.$this->selectionId($wait, $option['id']), $data['options']), $data['list_button_text'] ?? null, $transport['track_id']);
                }
            } catch (UazapiRejectedException $exception) {
                $this->rejected($waitId, $transport, in_array($exception->status, [400, 415, 422], true));

                return;
            } catch (Throwable) {
                // Uncertain transport: query the persisted intention; never send it again.
            }
        } else {
            try {
                $result = $this->client->messages($execution->connection, '', 0, $transport['track_id']);
                $messages = $result['messages'] ?? $result['data']['messages'] ?? $result['data'] ?? [];
                if (isset($result['id']) || isset($result['messageid'])) {
                    $messages = [$result];
                }
                if (isset($messages['id']) || isset($messages['messageid'])) {
                    $messages = [$messages];
                }
                $message = collect(is_array($messages) ? $messages : [])->first(fn ($item) => is_array($item) && ($item['track_id'] ?? $transport['track_id']) === $transport['track_id']);
            } catch (Throwable) {
                // Reconciliation has the same bounded budget even when the provider is down.
            }
        }
        $message = is_array($message['message'] ?? null) ? $message['message'] : $message;
        $state = strtolower((string) ($message['status'] ?? ''));
        if (in_array($state, ['failed', 'canceled', 'cancelled', 'error'], true)) {
            $this->rejected($waitId, $transport, true);

            return;
        }
        if (is_array($message) && ((is_string($message['id'] ?? null) && $message['id'] !== '') || (is_string($message['messageid'] ?? null) && $message['messageid'] !== '')) && ! in_array($state, ['queued', 'pending'], true)) {
            $this->confirmed($waitId, $transport, $message);

            return;
        }
        $this->locked($waitId, function (FlowExecution $execution, FlowMenuWait $wait) use ($transport): void {
            if (($wait->transport['track_id'] ?? null) !== $transport['track_id']) {
                return;
            }
            $pending = $wait->transport;
            // The initial send does not consume a query attempt.
            if ($pending['attempts'] >= config('flow-menu.confirmation_attempts', 24)) {
                $this->fail($wait->id, 'A confirmação do envio do Menu expirou.');

                return;
            }
            $pending['attempts']++;
            $pending['next_check_at'] = now()->addSeconds(config('flow-menu.confirmation_interval_seconds', 5))->toIso8601String();
            $wait->update(['transport' => $pending]);
            ProcessFlowMenu::dispatch($wait->id)->delay(config('flow-menu.confirmation_interval_seconds', 5))->afterCommit();
        });
    }

    private function confirmed(int $waitId, array $transport, array $message): void
    {
        $this->locked($waitId, function (FlowExecution $execution, FlowMenuWait $wait) use ($transport, $message): void {
            if (($wait->transport['track_id'] ?? null) !== $transport['track_id']) {
                return;
            }
            $early = $wait->transport['early_replies'] ?? [];
            $this->event($execution, $wait, 'sent', ['kind' => $transport['kind'], 'mode' => $transport['mode'], 'track_id' => $transport['track_id'], 'provider_id' => $message['id'] ?? $message['messageid']]);
            if ($transport['kind'] === 'error') {
                $wait->transport = ['track_id' => (string) Str::uuid(), 'kind' => 'menu', 'mode' => $wait->effective_format, 'attempts' => 0, 'reserved_at' => null];
                // null marks a retry menu that has not been transported yet.
            } else {
                if (! $wait->sent_at) {
                    $wait->sent_at = now();
                    $wait->expires_at = now()->addSeconds($this->data($execution, $wait)['timeout_seconds']);
                    ExpireFlowMenu::dispatch($wait->id)->delay($wait->expires_at)->afterCommit();
                } elseif ($wait->retry_queue) {
                    $queue = $wait->retry_queue;
                    array_shift($queue);
                    $wait->retry_queue = $queue;
                }
                $wait->status = 'waiting';
                $wait->effective_format = $transport['mode'];
                $wait->transport = null;
            }
            $wait->save();
            foreach ($early as $reply) {
                if ($wait->status !== 'waiting') {
                    break;
                }
                $this->consumeChoice($execution, $wait, $this->data($execution, $wait), $reply['option_id']);
            }
            if ($wait->transport || $wait->retry_queue) {
                ProcessFlowMenu::dispatch($wait->id)->afterCommit();
            }
        });
    }

    private function rejected(int $waitId, array $transport, bool $fallbackAllowed): void
    {
        $this->locked($waitId, function (FlowExecution $execution, FlowMenuWait $wait) use ($transport, $fallbackAllowed): void {
            if (($wait->transport['track_id'] ?? null) !== $transport['track_id']) {
                return;
            }
            if ($fallbackAllowed && $transport['kind'] === 'menu' && $transport['mode'] !== 'numeric') {
                $wait->transport = ['track_id' => (string) Str::uuid(), 'kind' => 'menu', 'mode' => 'numeric', 'attempts' => 0, 'reserved_at' => null];
                $wait->save();
                $this->event($execution, $wait, 'fallback', []);
                ProcessFlowMenu::dispatch($wait->id)->afterCommit();
            } else {
                $this->fail($wait->id, 'O envio do Menu foi rejeitado pela UAZAPI.');
            }
        });
    }

    private function fail(int $waitId, string $message): void
    {
        $this->locked($waitId, function (FlowExecution $execution, FlowMenuWait $wait) use ($message): void {
            $wait->update(['status' => 'failed', 'completed_at' => now(), 'transport' => null, 'retry_queue' => []]);
            $this->event($execution, $wait, 'failed', []);
            app(FlowRuntime::class)->finish($execution, 'failed', $message);
        });
    }

    private function event(FlowExecution $execution, FlowMenuWait $wait, string $type, array $metadata): void
    {
        DB::table('flow_events')->insert(['workspace_id' => $execution->workspace_id, 'flow_execution_id' => $execution->id, 'node_id' => $wait->node_id,
            'event_type' => 'menu_'.$type, 'metadata' => json_encode(['wait_id' => $wait->id, ...$metadata]), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function recoverExecution(int $executionId): void
    {
        FlowMenuWait::where('flow_execution_id', $executionId)->whereIn('status', ['sending', 'waiting'])->get()->each(function (FlowMenuWait $wait): void {
            if ($wait->expires_at && now()->gte($wait->expires_at)) {
                ExpireFlowMenu::dispatch($wait->id)->afterCommit();
            } elseif ($wait->status === 'sending' || $wait->transport || $wait->retry_queue) {
                ProcessFlowMenu::dispatch($wait->id)->afterCommit();
            }
        });
    }

    public function tick(): int
    {
        $count = 0;
        FlowMenuWait::whereIn('status', ['sending', 'waiting'])->where('updated_at', '<=', now()->subSeconds(5))->chunkById(100, function ($waits) use (&$count): void {
            foreach ($waits as $wait) {
                $this->recoverExecution($wait->flow_execution_id);
                $count++;
            }
        });

        return $count;
    }
}
