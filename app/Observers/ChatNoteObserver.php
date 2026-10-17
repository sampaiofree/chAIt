<?php

namespace App\Observers;

use App\Models\Note;
use App\Services\Chat\ChatPublisher;

class ChatNoteObserver
{
    public function saved(Note $note): void
    {
        app(ChatPublisher::class)->send($note->workspace_id, 'conversation.invalidate', ['id' => $note->thread_id], $note->thread_id);
    }
}
