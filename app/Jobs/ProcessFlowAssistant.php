<?php

namespace App\Jobs;

use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\FlowAssistant\FlowAssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessFlowAssistant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 180;

    public function __construct(public int $runId)
    {
        if (config('chat.enabled')) {
            $this->tries = 0;
        }
        $this->onQueue(config('chat.enabled') ? 'ai' : 'default');
    }

    public function middleware(): array
    {
        return [new WorkspaceCapacity('ai')];
    }

    public function handle(FlowAssistantService $assistant): void
    {
        $assistant->process($this->runId);
    }

    public function failed(\Throwable $error): void
    {
        app(FlowAssistantService::class)->pause($this->runId, 'A execução foi interrompida. Retome a partir da prévia compartilhada.');
    }
}
