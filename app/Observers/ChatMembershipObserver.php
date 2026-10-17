<?php

namespace App\Observers;

use App\Models\WorkspaceMembership;
use App\Services\Chat\ChatPublisher;
use Illuminate\Support\Facades\DB;

class ChatMembershipObserver
{
    public function saved(WorkspaceMembership $membership): void
    {
        $this->changed($membership);
    }

    public function deleted(WorkspaceMembership $membership): void
    {
        $this->changed($membership);
    }

    private function changed(WorkspaceMembership $membership): void
    {
        if (! config('chat.enabled')) {
            return;
        }
        DB::afterCommit(fn () => app(ChatPublisher::class)->emit($membership->user_id, ['type' => 'access.changed', 'workspace_id' => $membership->workspace_id, 'data' => []]));
    }
}
