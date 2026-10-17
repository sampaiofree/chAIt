<?php

namespace App\Services\Flows;

use App\Jobs\CleanupAiSession;
use App\Jobs\ExpireFlowWait;
use App\Jobs\ProcessFlowExecution;
use App\Models\AiSession;
use App\Models\ContactCustomFieldValue;
use App\Models\Flow;
use App\Models\FlowAsset;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Thread;
use App\Models\ThreadAssignment;
use App\Models\UazapiConnection;
use App\Services\Chat\ChatOperations;
use App\Services\Sequences\SequenceEnrollmentService;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiRejectedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FlowRuntime
{
    public function start(FlowVersion $version, int $contactId, int $threadId, UazapiConnection $connection, string $source, int $depth = 0): ?FlowExecution
    {
        return app(FlowExecutionManager::class)->start($version, $contactId, $threadId, $connection, $source, $depth);
    }

    public function run(int $executionId): void
    {
        $lock = Cache::lock("flow-runtime:{$executionId}", 110);
        if (! $lock->get()) {
            return;
        }
        try {
            $this->runLocked($executionId);
        } finally {
            $lock->release();
        }
    }

    private function runLocked(int $executionId): void
    {
        $execution = FlowExecution::with(['version', 'contact', 'thread', 'connection'])->find($executionId);
        if (! $execution || $execution->status !== 'running') {
            return;
        }
        $resumeAt = $execution->context['resume_at'] ?? null;
        if ($resumeAt) {
            $due = Carbon::parse($resumeAt);
            if (now()->lt($due)) {
                ProcessFlowExecution::dispatch($execution->id)->delay($due)->afterCommit();

                return;
            }
            app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution): void {
                $execution->refresh();
                $context = $execution->context;
                unset($context['resume_at']);
                $execution->update(['context' => $context]);
            });
        }
        if ($this->isPaused($execution)) {
            $this->finish($execution, 'interrupted', 'Automação pausada.');

            return;
        }
        $graph = app(FlowGraphValidator::class)->normalize($execution->version->graph);
        $nodes = collect($graph['nodes'])->keyBy('id');
        $edges = collect($graph['edges']);
        $steps = 0;
        while ($execution->status === 'running' && $steps++ < 300) {
            $execution->refresh();
            if ($execution->status !== 'running') {
                return;
            }
            $node = $nodes->get($execution->current_node_id);
            if (! $node) {
                $this->finish($execution, 'failed', 'O bloco atual não existe na versão publicada.');

                return;
            }
            $type = $node['type'];
            $data = $node['data'] ?? [];
            $context = $execution->context ?? [];
            if ($type === 'start') {
                $this->advance($execution, $edges, 'next');

                continue;
            }
            if ($type === 'content') {
                $items = $data['items'] ?? [];
                $index = (int) ($context['item_index'] ?? 0);
                if ($index >= count($items)) {
                    app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution): void {
                        $execution->refresh();
                        $context = $execution->context;
                        unset($context['item_index']);
                        $execution->update(['context' => $context]);
                    });
                    $this->advance($execution, $edges, 'next');

                    continue;
                }
                $item = $items[$index];
                if ($item['kind'] === 'delay') {
                    app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $index, $item): void {
                        $execution->refresh();
                        if ($execution->status !== 'running') {
                            return;
                        }
                        $context = $execution->context;
                        $context['item_index'] = $index + 1;
                        $context['resume_at'] = now()->addSeconds((int) $item['seconds'])->toIso8601String();
                        $execution->update(['context' => $context]);
                    });
                    ProcessFlowExecution::dispatch($execution->id)->delay((int) $item['seconds'])->afterCommit();

                    return;
                }
                if ($this->isPaused($execution)) {
                    $this->finish($execution, 'interrupted', 'Automação pausada.');

                    return;
                }
                $trackId = (string) Str::uuid();
                $reserved = false;
                try {
                    if (($context['pending_item_id'] ?? null) === $item['id']) {
                        try {
                            $providerMessage = $this->findTrackedMessage($execution, (string) $context['pending_track_id']);
                        } catch (Throwable) {
                            $providerMessage = null;
                        }
                        $state = strtolower((string) (data_get($providerMessage, 'status') ?? data_get($providerMessage, 'message.status') ?? ''));
                        if ($providerMessage && in_array($state, ['failed', 'canceled', 'cancelled', 'error'], true)) {
                            throw new TemplateContentException('transport_rejected', 'transport');
                        }
                        if (! $providerMessage || in_array($state, ['queued', 'pending'], true) || ! (data_get($providerMessage, 'id') ?? data_get($providerMessage, 'messageid') ?? (data_get($providerMessage, 'message.id') ?? data_get($providerMessage, 'message.messageid')))) {
                            $attempts = (int) ($context['pending_attempts'] ?? 1) + 1;
                            if ($attempts > 24) {
                                throw new TemplateContentException('result_unknown', 'transport');
                            }
                            $this->deferContentItem($execution, $attempts);

                            return;
                        }
                        $this->confirmItem($execution, $index, $item['id'], (string) $context['pending_track_id'], $providerMessage);

                        return;
                    }
                    $item = $this->prepareContentItem($execution, $item);
                    if ($item['kind'] === 'button') {
                        app(FlowGraphValidator::class)->validate($graph);
                        $issues = app(FlowContentButton::class)->issues([$item]);
                        if ($issues !== []) {
                            throw ValidationException::withMessages($issues);
                        }
                    }
                    // Persist intent before transport. A recovered job consults this identifier instead of sending again.
                    $context['pending_item_id'] = $item['id'];
                    $context['pending_track_id'] = $trackId;
                    $context['pending_attempts'] = 1;
                    $reserved = app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $node, $context, $item, $graph): bool {
                        $execution->refresh();
                        if ($execution->status !== 'running' || $execution->current_node_id !== $node['id']) {
                            return false;
                        }
                        $current = $execution->context;
                        foreach (['pending_item_id', 'pending_track_id', 'pending_attempts'] as $key) {
                            $current[$key] = $context[$key];
                        }
                        if ($item['kind'] === 'button' && $item['button']['kind'] === 'reply') {
                            $next = collect($graph['edges'])->first(fn ($edge) => $edge['source'] === $node['id'] && $edge['sourceHandle'] === 'next');
                            $target = collect($graph['nodes'])->firstWhere('id', $next['target'] ?? null);
                            $selection = app(FlowContentButton::class)->replyId($execution, $node['id'], $item['id']);
                            $current['content_buttons'][$selection] = ['node_id' => $node['id'], 'item_id' => $item['id'], 'wait_node_id' => ($target['type'] ?? '') === 'wait_reply' ? $target['id'] : null];
                        }
                        $execution->update(['context' => $current]);

                        return true;
                    });
                    if (! $reserved) {
                        return;
                    }
                    $providerMessage = match ($item['kind']) {
                        'text' => $this->sendContentText($execution, $item['text'], $trackId),
                        'button' => $this->sendContentButton($execution, $node['id'], $item, $trackId),
                        default => $this->sendAsset($execution, $item, $trackId),
                    };
                    $state = strtolower((string) (data_get($providerMessage, 'status') ?? data_get($providerMessage, 'message.status') ?? ''));
                    if (in_array($state, ['failed', 'canceled', 'cancelled', 'error'], true)) {
                        throw new TemplateContentException('transport_rejected', 'transport');
                    }
                    if (in_array($state, ['queued', 'pending'], true) || ! (data_get($providerMessage, 'id') ?? data_get($providerMessage, 'messageid') ?? (data_get($providerMessage, 'message.id') ?? data_get($providerMessage, 'message.messageid')))) {
                        $this->deferContentItem($execution, 1);

                        return;
                    }
                } catch (Throwable $exception) {
                    if ($reserved && ! $exception instanceof TemplateContentException && ! $exception instanceof UazapiRejectedException) {
                        $this->deferContentItem($execution, 1);

                        return;
                    }
                    $this->skipContentItem($execution, $node['id'], $index, $item['id'], $exception instanceof TemplateContentException ? $exception->reason : ($exception instanceof UazapiRejectedException ? 'transport_rejected' : 'invalid_content'), $exception instanceof TemplateContentException ? $exception->field : 'item');

                    return;
                }
                $this->confirmItem($execution, $index, $item['id'], $trackId, $providerMessage);

                return;
            }
            if ($type === 'menu') {
                if (isset($context['resume_handle'])) {
                    $handle = $context['resume_handle'];
                    unset($context['resume_handle']);
                    $execution->update(['context' => $context]);
                    $this->advance($execution, $edges, $handle);

                    continue;
                }
                app(FlowMenuRuntime::class)->start($execution, $node);

                return;
            }
            if ($type === 'wait_reply') {
                if ($this->isPaused($execution)) {
                    $this->finish($execution, 'interrupted', 'Automação pausada.');

                    return;
                }
                if (isset($context['resume_handle'])) {
                    $handle = $context['resume_handle'];
                    unset($context['resume_handle']);
                    $execution->context = $context;
                    $execution->save();
                    $this->advance($execution, $edges, $handle);

                    continue;
                }
                app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $node, $data): void {
                    $execution->refresh();
                    if ($execution->status !== 'running') {
                        return;
                    }
                    $waitId = DB::table('flow_waits')->insertGetId([
                        'workspace_id' => $execution->workspace_id, 'flow_execution_id' => $execution->id, 'node_id' => $node['id'],
                        'field_id' => $data['field_id'], 'status' => 'waiting', 'expires_at' => now()->addSeconds((int) $data['timeout_seconds']),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $execution->forceFill(['status' => 'waiting_reply'])->save();
                    ExpireFlowWait::dispatch($waitId)->delay(now()->addSeconds((int) $data['timeout_seconds']))->afterCommit();
                    $this->event($execution, 'waiting_reply', $node['id'], ['wait_id' => $waitId]);
                    app(FlowInboundProcessor::class)->consumeEarlyButtonReply($execution, $node['id']);
                });

                return;
            }
            if ($type === 'ai') {
                app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $node): void {
                    $execution->refresh();
                    if ($execution->status === 'running') {
                        app(AiSessionService::class)->start($execution, $node);
                    }
                });

                return;
            }
            if ($type === 'condition') {
                app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $node, $data, $edges): void {
                    $execution->refresh();
                    if ($execution->status !== 'running' || $execution->current_node_id !== $node['id']) {
                        return;
                    }
                    try {
                        $yes = app(FlowCondition::class)->evaluate($data, $execution->workspace, $execution->contact_id);
                    } catch (ValidationException) {
                        $this->finish($execution, 'failed', 'Configuração inválida no bloco Condição.');

                        return;
                    }
                    $this->advance($execution, $edges, $yes ? 'yes' : 'no');
                });

                continue;
            }
            if ($type === 'jump_flow') {
                if ((int) ($context['depth'] ?? 0) >= 20) {
                    $this->finish($execution, 'failed', 'Limite de saltos entre fluxos atingido.');

                    return;
                }
                $target = Flow::query()->where('workspace_id', $execution->workspace_id)->whereKey($data['target_flow_id'])->first();
                $version = $target?->publishedVersion;
                if ($execution->sequence_enrollment_id) {
                    $sequenceVersionId = SequenceEnrollment::whereKey($execution->sequence_enrollment_id)->value('sequence_version_id');
                    $pinned = DB::table('sequence_version_flows')->where('sequence_version_id', $sequenceVersionId)->where('flow_id', $data['target_flow_id'])->value('flow_version_id');
                    $version = $pinned ? FlowVersion::find($pinned) : null;
                }
                if (! $target || ! $version) {
                    $this->finish($execution, 'failed', 'O fluxo de destino não possui versão publicada.');

                    return;
                }
                app(FlowExecutionManager::class)->handoff($execution, $version);

                return;
            }
            if ($type === 'operations') {
                foreach ($data['actions'] ?? [] as $actionIndex => $action) {
                    if ($this->isPaused($execution)) {
                        $this->finish($execution, 'interrupted', 'Automação pausada.');

                        return;
                    }
                    $contact = $execution->contact;
                    if ($action['kind'] === 'add_to_sequence') {
                        $sequence = Sequence::where('workspace_id', $execution->workspace_id)->findOrFail($action['sequence_id']);
                        app(SequenceEnrollmentService::class)->enroll($execution->thread, $sequence, 'flow', "flow:{$execution->id}:{$node['id']}:{$actionIndex}");
                    } elseif (in_array($action['kind'], ['add_tag', 'remove_tag'], true)) {
                        if ($action['kind'] === 'add_tag') {
                            DB::table('contact_tag')->updateOrInsert(['contact_id' => $contact->id, 'tag_id' => $action['tag_id']], ['workspace_id' => $execution->workspace_id, 'created_at' => now(), 'updated_at' => now()]);
                        } else {
                            DB::table('contact_tag')->where('contact_id', $contact->id)->where('tag_id', $action['tag_id'])->delete();
                        }
                    } elseif ($action['kind'] === 'set_field') {
                        ContactCustomFieldValue::updateOrCreate(['contact_id' => $contact->id, 'field_id' => $action['field_id']], ['workspace_id' => $execution->workspace_id, 'value' => $action['value']]);
                    } elseif ($action['kind'] === 'notify_member') {
                        $member = $execution->workspace->memberships()->where('user_id', $action['user_id'])->with('user')->first();
                        if (! $member?->user?->phone) {
                            throw new \RuntimeException('Membro sem telefone cadastrado.');
                        }
                        if (! $this->canSend($execution)) {
                            return;
                        }
                        if (config('chat.enabled')) {
                            app(ChatOperations::class)->transport($execution->connection, 'text', ['to' => $member->user->phone, 'text' => $action['text'], 'track_id' => (string) Str::uuid(), 'allow_completed' => true]);
                        } else {
                            $this->sendTextTo($execution->connection, $member->user->phone, $action['text'], (string) Str::uuid());
                        }
                    } elseif ($action['kind'] === 'open_human') {
                        DB::transaction(function () use ($execution, $action): void {
                            $execution->contact()->lockForUpdate()->firstOrFail();
                            $thread = $execution->thread;
                            if (! empty($action['user_id'])) {
                                $thread->assignments()->where('state', 'active')->update(['state' => 'released', 'updated_at' => now()]);
                                ThreadAssignment::create(['workspace_id' => $execution->workspace_id, 'thread_id' => $thread->id, 'assigned_to_user_id' => $action['user_id'], 'assigned_by_user_id' => null, 'state' => 'active']);
                                $thread->assigned_to_user_id = $action['user_id'];
                                $thread->last_assigned_to_user_id = $action['user_id'];
                            }
                            $thread->forceFill([
                                'status' => empty($action['user_id']) ? 'open' : 'in_progress',
                                'human_opened_at' => $thread->status === 'completed' ? now() : ($thread->human_opened_at ?? now()),
                                'finished_at' => null,
                            ])->save();
                            $this->pause($execution, 'human', null);
                            $this->finish($execution, 'interrupted', 'Atendimento humano iniciado.');
                        });

                        return;
                    } elseif ($action['kind'] === 'pause_automation') {
                        $until = ($action['pause_mode'] ?? '') === 'timed' ? now()->addSeconds((int) $action['pause_seconds']) : null;
                        $this->pause($execution, 'manual', $until);
                        $this->finish($execution, 'interrupted', 'Automações pausadas.');

                        return;
                    }
                }
                $this->advance($execution, $edges, 'next');

                continue;
            }
            $this->finish($execution, 'failed', 'Tipo de bloco desconhecido.');

            return;
        }
        if ($steps >= 300) {
            $this->finish($execution, 'failed', 'Limite de passos da execução atingido.');
        }
    }

    public function isPaused(FlowExecution $execution): bool
    {
        DB::table('automation_pauses')->where('uazapi_connection_id', $execution->uazapi_connection_id)->where('contact_id', $execution->contact_id)
            ->whereNull('released_at')->whereNotNull('expires_at')->where('expires_at', '<=', now())->update(['released_at' => now(), 'updated_at' => now()]);
        $manual = DB::table('automation_pauses')->where('uazapi_connection_id', $execution->uazapi_connection_id)->where('contact_id', $execution->contact_id)
            ->whereNull('released_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
        $thread = Thread::query()->find($execution->thread_id);
        $human = in_array($thread?->status, ['open', 'in_progress'], true) && $thread?->human_opened_at !== null;

        return $manual || $human;
    }

    public function pause(FlowExecution $execution, string $reason, $expiresAt): void
    {
        app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function ($context) use ($execution, $reason, $expiresAt): void {
            DB::table('automation_pauses')->insert([
                'workspace_id' => $execution->workspace_id, 'uazapi_connection_id' => $execution->uazapi_connection_id,
                'contact_id' => $execution->contact_id, 'thread_id' => $execution->thread_id, 'flow_execution_id' => $execution->id,
                'reason' => $reason, 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(FlowExecutionManager::class)->changed($context);
        });
    }

    public function finish(FlowExecution $execution, string $status, ?string $error = null, ?string $reason = null): void
    {
        $reason ??= match (true) {
            $status === 'completed' => 'completed',
            $status === 'failed' => 'flow_failed',
            str_contains($error ?? '', 'palavra-chave') => 'keyword',
            str_contains($error ?? '', 'humano') => 'human',
            default => 'automation_pause',
        };
        app(FlowExecutionManager::class)->finish($execution, $status, $error, $reason, function () use ($execution, $status, $reason): void {
            $context = $execution->context;
            unset($context['early_button_reply']);
            $execution->update(['context' => $context]);
            DB::table('flow_menu_waits')->where('flow_execution_id', $execution->id)->whereIn('status', ['sending', 'waiting'])->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now(), 'transport' => null, 'retry_queue' => '[]']);
            DB::table('flow_waits')->where('flow_execution_id', $execution->id)->where('status', 'waiting')->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
            AiSession::where('flow_execution_id', $execution->id)->where('status', 'active')->get()->each(function (AiSession $session): void {
                $session->forceFill(['status' => 'interrupted', 'end_reason' => 'flow_finished', 'ended_at' => now()])->save();
                CleanupAiSession::dispatch($session->id)->afterCommit();
            });
            $this->event($execution, $status, $execution->current_node_id, ['reason' => $reason]);
        });
    }

    public function continueFromAi(FlowExecution $execution): void
    {
        $edges = collect($execution->version->graph['edges']);
        $this->advance($execution, $edges, 'continue');
        if ($execution->status === 'running') {
            ProcessFlowExecution::dispatch($execution->id)->afterCommit();
        }
    }

    public function openHumanFromAi(FlowExecution $execution): void
    {
        DB::transaction(function () use ($execution): void {
            $execution->contact()->lockForUpdate()->firstOrFail();
            $thread = $execution->thread;
            $thread->forceFill([
                'status' => 'open',
                'human_opened_at' => $thread->status === 'completed' ? now() : ($thread->human_opened_at ?? now()),
                'finished_at' => null,
            ])->save();
            $this->pause($execution, 'human', null);
            $this->finish($execution, 'interrupted', 'Atendimento humano iniciado pela IA.');
        });
    }

    private function advance(FlowExecution $execution, $edges, string $handle): void
    {
        $expectedNode = $execution->current_node_id;
        app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $edges, $handle, $expectedNode): void {
            $execution->refresh();
            if (! in_array($execution->status, FlowExecutionManager::ACTIVE, true) || $execution->current_node_id !== $expectedNode) {
                return;
            }
            $edge = $edges->first(fn ($edge) => $edge['source'] === $execution->current_node_id && $edge['sourceHandle'] === $handle);
            if (! $edge) {
                $this->finish($execution, 'completed');

                return;
            }
            $execution->forceFill(['current_node_id' => $edge['target'], 'status' => 'running'])->save();
            $this->event($execution, 'transition', $edge['target'], ['from' => $edge['source'], 'handle' => $handle]);
        });
    }

    private function prepareContentItem(FlowExecution $execution, array $item): array
    {
        $templates = app(ContactTemplate::class);
        $snapshot = $templates->snapshot($execution->workspace_id, $execution->contact_id);
        if (in_array($item['kind'], ['text', 'button'], true)) {
            $item['text'] = $templates->rendered($item['text'] ?? '', $snapshot, 4000, 'text', true);
        }
        if ($item['kind'] === 'media') {
            $item['caption'] = $templates->rendered($item['caption'] ?? '', $snapshot, 1024, 'caption');
            $asset = FlowAsset::query()->where('workspace_id', $execution->workspace_id)->where('flow_id', $execution->flow_id)->find($item['asset_id'] ?? null);
            if (! $asset || ! Storage::disk($asset->disk)->exists($asset->path)) {
                throw new TemplateContentException('asset_unavailable', 'asset_id');
            }
        }
        if ($item['kind'] === 'button') {
            $item['button']['label'] = $templates->rendered($item['button']['label'] ?? '', $snapshot, 120, 'label', true);
            $kind = $item['button']['kind'];
            if ($kind === 'url') {
                $item['button']['url'] = $templates->rendered($item['button']['url'] ?? '', $snapshot, 4000, 'url', true);
            }
            if ($kind === 'copy') {
                $item['button']['copy_text'] = $templates->rendered($item['button']['copy_text'] ?? '', $snapshot, 4000, 'copy', true);
            }
        }

        return $item;
    }

    private function skipContentItem(FlowExecution $execution, string $nodeId, int $index, string $itemId, string $reason, string $field): void
    {
        app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $nodeId, $index, $itemId, $reason, $field): void {
            $execution->refresh();
            if ($execution->status !== 'running' || $execution->current_node_id !== $nodeId || (int) ($execution->context['item_index'] ?? 0) !== $index) {
                return;
            }
            $context = $execution->context;
            unset($context['pending_item_id'], $context['pending_track_id'], $context['pending_attempts']);
            foreach ($context['content_buttons'] ?? [] as $key => $ref) {
                if ($ref['item_id'] === $itemId && $ref['node_id'] === $nodeId) {
                    unset($context['content_buttons'][$key]);
                    if (($context['early_button_reply']['selection'] ?? null) === $key) {
                        unset($context['early_button_reply']);
                    }
                }
            }
            $context['item_index'] = $index + 1;
            $execution->update(['context' => $context]);
            $this->event($execution, $reason === 'result_unknown' ? 'send_unknown' : 'send_failed', $nodeId, ['version_id' => $execution->flow_version_id, 'item_id' => $itemId, 'field' => $field, 'reason' => $reason, 'outcome' => $reason === 'result_unknown' ? 'unknown' : 'skipped']);
            ProcessFlowExecution::dispatch($execution->id)->afterCommit();
        });
    }

    private function sendContentText(FlowExecution $execution, string $text, string $trackId): array
    {
        if (! $this->canSend($execution)) {
            return [];
        }
        try {
            return app(UazapiClient::class)->sendText($execution->connection, $execution->thread->external_chat_id ?: $execution->contact->phone, $text, $trackId);
        } catch (UazapiRejectedException $exception) {
            if ($exception->status !== 408) {
                throw $exception;
            }

            return [];
        } catch (Throwable) {
            return [];
        }
    }

    private function confirmItem(FlowExecution $execution, int $index, string $itemId, string $trackId, array $message): void
    {
        $nodeId = $execution->current_node_id;
        app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $nodeId, $index, $itemId, $trackId, $message): void {
            $execution->refresh();
            if ($execution->status !== 'running' || $execution->current_node_id !== $nodeId || ($execution->context['pending_track_id'] ?? null) !== $trackId || (int) ($execution->context['item_index'] ?? 0) !== $index || ($execution->context['pending_item_id'] ?? null) !== $itemId) {
                return;
            }
            $context = $execution->context;
            unset($context['pending_item_id'], $context['pending_track_id'], $context['pending_attempts']);
            $context['item_index'] = $index + 1;
            $execution->update(['context' => $context]);
            $this->event($execution, 'message_confirmed', $execution->current_node_id, [
                'item_id' => $itemId, 'track_id' => $trackId,
                'provider_id' => data_get($message, 'id') ?? data_get($message, 'messageid') ?? (data_get($message, 'message.id') ?? data_get($message, 'message.messageid')),
                'provider_status' => data_get($message, 'status') ?? data_get($message, 'message.status'),
            ]);
            ProcessFlowExecution::dispatch($execution->id)->afterCommit();
        });
    }

    private function deferContentItem(FlowExecution $execution, int $attempts): void
    {
        app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($execution, $attempts): void {
            $execution->refresh();
            if ($execution->status !== 'running') {
                return;
            }
            $context = $execution->context;
            $context['pending_attempts'] = $attempts;
            $execution->update(['context' => $context]);
            ProcessFlowExecution::dispatch($execution->id)->delay(5)->afterCommit();
        });
    }

    private function sendContentButton(FlowExecution $execution, string $nodeId, array $item, string $trackId): array
    {
        if (! $this->canSend($execution) || $execution->status !== 'running' || $execution->current_node_id !== $nodeId) {
            throw new \RuntimeException('Execução encerrada antes do envio.');
        }
        try {
            return app(UazapiClient::class)->sendMenu($execution->connection,
                $execution->thread->external_chat_id ?: $execution->contact->phone, 'button', $item['text'],
                [app(FlowContentButton::class)->choice($execution, $nodeId, $item)], null, $trackId);
        } catch (UazapiRejectedException $exception) {
            if ($exception->status !== 408) {
                throw $exception;
            }

            return [];
        } catch (Throwable) {
            // The provider may have accepted it. Reconcile the persisted intent; never resend.
            return [];
        }
    }

    public function canSend(FlowExecution $execution): bool
    {
        $execution->refresh();

        return in_array($execution->status, FlowExecutionManager::ACTIVE, true) && ! $this->isPaused($execution);
    }

    private function sendText(FlowExecution $execution, string $text, string $trackId): array
    {
        if (! $this->canSend($execution)) {
            throw new \RuntimeException('Execução encerrada antes do envio.');
        }

        return $this->sendTextTo($execution->connection, $execution->thread->external_chat_id ?: $execution->contact->phone, $text, $trackId);
    }

    private function sendTextTo(UazapiConnection $connection, string $to, string $text, string $trackId): array
    {
        try {
            return app(UazapiClient::class)->sendText($connection, $to, $text, $trackId);
        } catch (Throwable $exception) {
            $found = app(UazapiClient::class)->messages($connection, $to, 0, $trackId);
            $message = $this->firstMessage($found);
            if (! $message) {
                throw $exception;
            }

            return $message;
        }
    }

    private function sendAsset(FlowExecution $execution, array $item, string $trackId): array
    {
        if (! $this->canSend($execution)) {
            throw new \RuntimeException('Execução encerrada antes do envio.');
        }
        $asset = FlowAsset::query()->where('workspace_id', $execution->workspace_id)->where('flow_id', $execution->flow_id)->findOrFail($item['asset_id']);
        if (config('chat.enabled')) {
            return app(ChatOperations::class)->transport($execution->connection, 'asset', ['asset_id' => $asset->id, 'to' => $execution->thread->external_chat_id ?: $execution->contact->phone, 'media_type' => $item['media_type'], 'caption' => $item['caption'] ?? null, 'track_id' => $trackId]);
        }
        $file = Storage::disk($asset->disk)->get($asset->path);
        $encoded = 'data:'.$asset->mime_type.';base64,'.base64_encode($file);
        try {
            return app(UazapiClient::class)->sendMedia($execution->connection, $execution->thread->external_chat_id ?: $execution->contact->phone, $encoded,
                $item['media_type'], $item['caption'] ?? null, $asset->original_name, $asset->mime_type, $trackId);
        } catch (UazapiRejectedException $exception) {
            if ($exception->status !== 408) {
                throw $exception;
            }

            return [];
        } catch (Throwable) {
            return [];
        }
    }

    private function findTrackedMessage(FlowExecution $execution, string $trackId): ?array
    {
        return $this->firstMessage(app(UazapiClient::class)->messages($execution->connection, $execution->thread->external_chat_id ?: $execution->contact->phone, 0, $trackId));
    }

    private function firstMessage(array $response): ?array
    {
        foreach (['messages', 'data', 'items'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return reset($response[$key]) ?: null;
            }
        }

        return isset($response['id']) || isset($response['messageid']) || isset($response['message']['id']) || isset($response['message']['messageid']) ? $response : null;
    }

    public function event(FlowExecution $execution, string $type, ?string $nodeId, array $metadata = []): void
    {
        DB::table('flow_events')->insert(['workspace_id' => $execution->workspace_id, 'flow_execution_id' => $execution->id, 'event_type' => $type,
            'node_id' => $nodeId, 'metadata' => json_encode(['version_id' => $execution->flow_version_id, ...$metadata], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
