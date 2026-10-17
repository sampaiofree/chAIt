<?php

namespace App\Jobs;

use App\Models\FlowExecution;
use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Flows\FlowRuntime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessFlowExecution implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 90;

    public function __construct(public int $executionId, public int $delaySeconds = 0)
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

    public function handle(FlowRuntime $runtime): void
    {
        $runtime->run($this->executionId);
    }

    public function failed(\Throwable $exception): void
    {
        $execution = FlowExecution::find($this->executionId);
        if ($execution?->status === 'running' && isset($execution->context['pending_track_id'])) {
            self::dispatch($execution->id)->delay(5);

            return;
        }
        if ($execution && in_array($execution->status, ['running', 'waiting_reply', 'waiting_ai', 'waiting_menu'], true)) {
            app(FlowRuntime::class)->finish($execution, 'failed', 'Falha inesperada na execução.');
        }
    }
}
