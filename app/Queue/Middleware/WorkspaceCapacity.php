<?php

namespace App\Queue\Middleware;

use App\Models\AiSession;
use App\Models\FlowExecution;
use App\Models\FlowMenuWait;
use App\Models\SequenceEnrollment;
use App\Services\Chat\TransportContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WorkspaceCapacity
{
    public function __construct(private string $category) {}

    public function handle(object $job, callable $next): void
    {
        if (! config('chat.enabled')) {
            $next($job);

            return;
        }
        $workspace = method_exists($job, 'workspaceId') ? $job->workspaceId() : $this->workspace($job);
        if (! $workspace) {
            $next($job);

            return;
        }
        for ($i = 0; $i < config('chat.workspace_limits.'.$this->category, 1); $i++) {
            $lock = Cache::lock('chat-capacity:'.$workspace.':'.$this->category.':'.$i, 240);
            if ($lock->get()) {
                $context = app(TransportContext::class);
                $context->executionId = isset($job->executionId) ? $job->executionId : (isset($job->sessionId) ? AiSession::whereKey($job->sessionId)->value('flow_execution_id') : (isset($job->waitId) ? FlowMenuWait::whereKey($job->waitId)->value('flow_execution_id') : null));
                try {
                    $next($job);
                } finally {
                    $context->executionId = null;
                    $lock->release();
                }

                return;
            }
        }
        $job->release(random_int(1, 3));
    }

    private function workspace(object $job): ?int
    {
        $map = ['executionId' => FlowExecution::class, 'sessionId' => AiSession::class, 'waitId' => FlowMenuWait::class, 'enrollmentId' => SequenceEnrollment::class];
        foreach ($map as $field => $model) {
            if (isset($job->$field)) {
                return $model::whereKey($job->$field)->value('workspace_id');
            }
        }
        if (isset($job->runId)) {
            return DB::table('flow_ai_runs')->join('flow_ai_conversations', 'flow_ai_conversations.id', '=', 'flow_ai_runs.conversation_id')->where('flow_ai_runs.id', $job->runId)->value('flow_ai_conversations.workspace_id');
        }

        return null;
    }
}
