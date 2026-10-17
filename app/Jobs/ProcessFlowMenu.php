<?php

namespace App\Jobs;

use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Flows\FlowMenuRuntime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessFlowMenu implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 90;

    public function __construct(public int $waitId)
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

    public function handle(FlowMenuRuntime $menus): void
    {
        $menus->process($this->waitId);
    }
}
