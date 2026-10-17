<?php

namespace App\Services\Chat;

use App\Enums\WorkspaceRole;
use App\Events\ChatUpdate;
use App\Models\Thread;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatPublisher
{
    private bool $buffering = false;

    private array $buffer = [];

    public function begin(): void
    {
        $this->buffering = true;
        $this->buffer = [];
    }

    public function discard(): void
    {
        $this->buffering = false;
        $this->buffer = [];
    }

    public function flush(): void
    {
        $callbacks = $this->buffer;
        $this->discard();
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable) {
                Log::warning('Chat publication failed after domain commit.');
            }
        }
    }

    public function send(int $workspaceId, string $type, array $data, ?int $threadId = null, ?int $onlyUser = null, WorkspaceRole $minimumRole = WorkspaceRole::Agent): void
    {
        if (! config('chat.enabled')) {
            return;
        }
        if ($this->buffering) {
            $this->buffer[] = fn () => $this->send($workspaceId, $type, $data, $threadId, $onlyUser, $minimumRole);

            return;
        }
        DB::afterCommit(function () use ($workspaceId, $type, $data, $threadId, $onlyUser, $minimumRole): void {
            $thread = $threadId ? Thread::find($threadId) : null;
            if ($threadId && (! $thread || $thread->workspace_id !== $workspaceId)) {
                return;
            }
            $members = WorkspaceMembership::where('workspace_id', $workspaceId)->when($onlyUser, fn ($q) => $q->where('user_id', $onlyUser))->get();
            foreach ($members as $member) {
                if (! WorkspaceRole::from($member->role)->atLeast($minimumRole)) {
                    continue;
                }
                if ($thread && ! WorkspaceRole::from($member->role)->atLeast(WorkspaceRole::Admin) && ! $member->can_view_unassigned && $thread->assigned_to_user_id !== $member->user_id) {
                    // Removal contains only an internal ID, never a preview or contact data.
                    if ($type === 'conversation.changed') {
                        $this->emit($member->user_id, ['type' => 'conversation.removed', 'workspace_id' => $workspaceId, 'data' => ['id' => $threadId]]);
                    }

                    continue;
                }
                $this->emit($member->user_id, ['type' => $type, 'workspace_id' => $workspaceId, 'thread_id' => $threadId, 'data' => $data]);
            }
        });
    }

    public function thread(int $id): void
    {
        if (! config('chat.enabled')) {
            return;
        }
        if ($this->buffering) {
            $this->buffer[] = fn () => $this->thread($id);

            return;
        }
        DB::afterCommit(function () use ($id): void {
            $thread = Thread::find($id);
            if ($thread) {
                $this->send($thread->workspace_id, 'conversation.changed', app(ChatProjection::class)->serialize(collect([$thread]))->first(), $id);
            }
        });
    }

    public function emit(int $userId, array $payload): void
    {
        if (config('broadcasting.default') === 'log') {
            Log::error('Chat realtime requires a non-logging broadcaster.');

            return;
        }
        try {
            event(new ChatUpdate($userId, $payload));
        } catch (\Throwable) {
            Log::warning('Chat realtime delivery failed.', ['user_id' => $userId, 'event_type' => $payload['type']]);
        }
    }
}
