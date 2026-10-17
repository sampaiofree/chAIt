<?php

namespace App\Services\Chat;

use App\Enums\WorkspaceRole;
use App\Models\Thread;
use App\Models\ThreadAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogService;
use App\Services\Flows\FlowAutomationControl;
use Illuminate\Support\Facades\DB;

class HumanAssignment
{
    public function claim(Thread $thread, User $actor, User $assignee, Workspace $workspace, bool $allowTransfer): Thread
    {
        return DB::transaction(function () use ($thread, $actor, $assignee, $workspace, $allowTransfer): Thread {
            $thread->contact()->lockForUpdate()->firstOrFail();
            $thread->refresh();
            $previous = $thread->assigned_to_user_id;
            $membership = $actor->membershipFor($workspace);
            abort_unless($membership && (
                WorkspaceRole::from($membership->role)->atLeast(WorkspaceRole::Admin)
                || $membership->can_view_unassigned
                || $previous === $actor->id
            ), 404);
            abort_if($previous && $previous !== $assignee->id && ! $allowTransfer, 409, 'Esta conversa já tem responsável.');

            if ($previous !== $assignee->id) {
                $thread->assignments()->where('state', 'active')->update(['state' => 'released', 'updated_at' => now()]);
                ThreadAssignment::query()->create([
                    'workspace_id' => $workspace->id,
                    'thread_id' => $thread->id,
                    'assigned_to_user_id' => $assignee->id,
                    'assigned_by_user_id' => $actor->id,
                    'state' => 'active',
                ]);
                $thread->assigned_to_user_id = $assignee->id;
                $thread->last_assigned_to_user_id = $assignee->id;
            }

            $opened = $thread->status !== 'in_progress' || ! $thread->human_opened_at;
            if ($opened || $previous !== $assignee->id) {
                $thread->forceFill([
                    'status' => 'in_progress',
                    'human_opened_at' => $thread->human_opened_at ?? now(),
                    'finished_at' => null,
                ])->save();
                app(FlowAutomationControl::class)->humanOpened($thread);
                app(AuditLogService::class)->record($workspace, 'conversation.claimed', $actor, $thread, null, [
                    'previous_assignee_id' => $previous,
                    'assigned_to_user_id' => $assignee->id,
                    'transferred' => $previous !== null && $previous !== $assignee->id,
                ]);
            }

            return $thread;
        }, 3);
    }
}
