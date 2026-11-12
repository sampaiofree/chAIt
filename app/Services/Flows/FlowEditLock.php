<?php

namespace App\Services\Flows;

use App\Models\Flow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowEditLock
{
    public function acquire(Flow $flow, User $user, string $token): array
    {
        return DB::transaction(function () use ($flow, $user, $token): array {
            $flow = Flow::whereKey($flow->id)->lockForUpdate()->firstOrFail();
            abort_if($flow->is_active, 409, 'Inative o fluxo antes de editar.');
            $current = DB::table('flow_edit_locks')->where('flow_id', $flow->id)->first();
            if ($current && now()->lt($current->expires_at) && ($current->token !== $token || (int) $current->user_id !== $user->id)) {
                $name = User::whereKey($current->user_id)->value('name');

                return ['acquired' => false, 'editor' => $name, 'expires_at' => $current->expires_at];
            }
            if ($current && $current->token !== $token) {
                $this->cancelRuns($flow, $current->token);
            }
            DB::table('flow_edit_locks')->updateOrInsert(['flow_id' => $flow->id], [
                'user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addSeconds(60), 'updated_at' => now(), 'created_at' => now(),
            ]);

            return ['acquired' => true, 'editor' => $user->name];
        }, 3);
    }

    // Caller holds the flow row lock when a write is performed.
    public function assertHeld(Flow $flow, ?string $token, ?int $userId = null): void
    {
        abort_if($flow->is_active, 409, 'Inative o fluxo antes de editar.');
        abort_unless($this->held($flow, $token, $userId), 409, 'O bloqueio de edição expirou ou pertence a outra pessoa.');
    }

    public function held(Flow $flow, ?string $token, ?int $userId = null): bool
    {
        return ! $flow->is_active && $token !== null && Str::isUuid($token) && DB::table('flow_edit_locks')->where('flow_id', $flow->id)->where('token', $token)
            ->where('expires_at', '>', now())->when($userId, fn ($q) => $q->where('user_id', $userId))->exists();
    }

    public function release(Flow $flow, User $user, string $token): void
    {
        DB::transaction(function () use ($flow, $user, $token): void {
            $flow = Flow::whereKey($flow->id)->lockForUpdate()->firstOrFail();
            $deleted = DB::table('flow_edit_locks')->where('flow_id', $flow->id)->where('user_id', $user->id)->where('token', $token)->delete();
            if ($deleted) {
                $this->cancelRuns($flow, $token);
            }
        }, 3);
    }

    private function cancelRuns(Flow $flow, string $token): void
    {
        $conversationId = DB::table('flow_ai_conversations')->where('flow_id', $flow->id)->value('id');
        if ($conversationId) {
            $cancelled = DB::table('flow_ai_runs')->where('conversation_id', $conversationId)->where('edit_token', $token)
                ->whereIn('status', ['queued', 'running'])->update(['status' => 'paused', 'finished_at' => now(), 'error' => 'A edição foi encerrada.', 'provider_items' => null]);
            if ($cancelled) {
                DB::table('flow_ai_conversations')->where('id', $conversationId)->update(['state' => 'paused_on_error', 'updated_at' => now()]);
            }
        }
    }
}
