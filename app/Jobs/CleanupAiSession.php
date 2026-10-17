<?php

namespace App\Jobs;

use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Flows\AiSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupAiSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 5;

    public int $timeout = 180;

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function __construct(public int $sessionId)
    {
        if (config('chat.enabled')) {
            $this->tries = 0;
        }
        $this->onQueue(config('chat.enabled') ? 'ai' : 'automations');
    }

    public function middleware(): array
    {
        return [new WorkspaceCapacity('ai')];
    }

    public function handle(AiSessionService $sessions): void
    {
        $sessions->cleanup($this->sessionId);
    }
}
