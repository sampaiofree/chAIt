<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Transient only: never serialize message bodies into a broadcasting queue. */
class ChatUpdate implements ShouldBroadcastNow
{
    public function __construct(private int $userId, private array $update) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.update';
    }

    public function broadcastWith(): array
    {
        return $this->update;
    }
}
