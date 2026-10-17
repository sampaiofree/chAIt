<?php

namespace App\Jobs;

use App\Services\Flows\FlowMenuRuntime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExpireFlowMenu implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $waitId)
    {
        $this->onQueue('automations');
    }

    public function handle(FlowMenuRuntime $menus): void
    {
        $menus->expire($this->waitId);
    }
}
