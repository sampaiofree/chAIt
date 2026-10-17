<?php

namespace App\Jobs;

use App\Services\Flows\AiSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExpireAiSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $sessionId)
    {
        $this->onQueue('automations');
    }

    public function handle(AiSessionService $sessions): void
    {
        $sessions->expire($this->sessionId);
    }
}
