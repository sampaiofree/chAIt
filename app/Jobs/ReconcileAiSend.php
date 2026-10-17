<?php

namespace App\Jobs;

use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Flows\AiSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileAiSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 60;

    public function __construct(public int $sessionId)
    {
        if (config('chat.enabled')) {
            $this->tries = 0;
        }
        $this->onQueue('automations');
    }

    public function middleware(): array
    {
        return [new WorkspaceCapacity('automations')];
    }

    public function handle(AiSessionService $service): void
    {
        $service->reconcileSend($this->sessionId);
    }
}
