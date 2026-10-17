<?php

namespace App\Jobs;

use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Sequences\SequenceScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSequenceStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(public int $enrollmentId, public int $generation)
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

    public function handle(SequenceScheduler $scheduler): void
    {
        $scheduler->execute($this->enrollmentId, $this->generation);
    }
}
