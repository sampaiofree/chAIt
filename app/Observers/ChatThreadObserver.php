<?php

namespace App\Observers;

use App\Models\Thread;
use App\Services\Chat\ChatPublisher;

class ChatThreadObserver
{
    public function saving(Thread $thread): void
    {
        if (config('chat.enabled') && $thread->exists && $thread->isDirty() && ! $thread->isDirty('chat_revision')) {
            $thread->chat_revision++;
        }
    }

    public function saved(Thread $thread): void
    {
        app(ChatPublisher::class)->thread($thread->id);
    }
}
