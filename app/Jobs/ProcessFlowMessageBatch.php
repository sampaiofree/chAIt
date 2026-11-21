<?php

namespace App\Jobs;

use App\Models\FlowExecution;
use App\Models\FlowMessageBatch;
use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Flows\FlowMessageGrouping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessFlowMessageBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public int $tries = 0;

    public ?int $executionId = null;

    public function __construct(public int $batchId, public bool $ai)
    {
        $this->onQueue($ai && config('chat.enabled') ? 'ai' : 'automations');
    }

    public function workspaceId(): ?int
    {
        $this->executionId = FlowMessageBatch::whereKey($this->batchId)->value('flow_execution_id');

        return FlowExecution::whereKey($this->executionId)->value('workspace_id');
    }

    public function middleware(): array
    {
        return $this->ai ? [new WorkspaceCapacity('ai')] : [];
    }

    public function handle(FlowMessageGrouping $grouping): void
    {
        $grouping->process($this->batchId);
    }
}
