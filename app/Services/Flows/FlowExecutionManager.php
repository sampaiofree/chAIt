<?php

namespace App\Services\Flows;

use App\Events\AutomationContextChanged;
use App\Jobs\ProcessFlowExecution;
use App\Models\AutomationContext;
use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Thread;
use App\Models\UazapiConnection;
use Illuminate\Support\Facades\DB;

class FlowExecutionManager
{
    public const ACTIVE = ['running', 'waiting_reply', 'waiting_ai', 'waiting_menu'];

    public function locked(int $workspaceId, int $connectionId, int $contactId, callable $callback): mixed
    {
        return DB::transaction(function () use ($workspaceId, $connectionId, $contactId, $callback) {
            Contact::where('workspace_id', $workspaceId)->whereKey($contactId)->lockForUpdate()->firstOrFail();
            UazapiConnection::where('workspace_id', $workspaceId)->whereKey($connectionId)->firstOrFail();
            $context = AutomationContext::firstOrCreate([
                'workspace_id' => $workspaceId, 'uazapi_connection_id' => $connectionId, 'contact_id' => $contactId,
            ]);
            $context = AutomationContext::whereKey($context->id)->lockForUpdate()->firstOrFail();
            // Adopt executions created before this module was installed. Never silently choose between duplicates.
            $active = FlowExecution::where('workspace_id', $workspaceId)->where('uazapi_connection_id', $connectionId)
                ->where('contact_id', $contactId)->whereIn('status', self::ACTIVE)->lockForUpdate()->get();
            abort_if($active->count() > 1, 409, 'Existem execuções concorrentes legadas para este contato. Revise antes de continuar.');
            $activeId = $active->first()?->id;
            if ($context->active_execution_id !== $activeId) {
                $context->update(['active_execution_id' => $activeId]);
            }

            return $callback($context);
        }, 3);
    }

    public function blockers(AutomationContext $context): array
    {
        $reasons = [];
        if ($context->active_execution_id) {
            $reasons[] = 'flow';
        }
        $pauses = DB::table('automation_pauses')->where('workspace_id', $context->workspace_id)
            ->where('uazapi_connection_id', $context->uazapi_connection_id)->where('contact_id', $context->contact_id)
            ->whereNull('released_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('reason')->all();
        $reasons = array_merge($reasons, $pauses);
        if (Thread::where('workspace_id', $context->workspace_id)->where('uazapi_connection_id', $context->uazapi_connection_id)
            ->where('contact_id', $context->contact_id)->whereIn('status', ['open', 'in_progress'])->whereNotNull('human_opened_at')->exists()) {
            $reasons[] = 'human';
        }

        return array_values(array_unique($reasons));
    }

    public function start(FlowVersion $version, int $contactId, int $threadId, UazapiConnection $connection, string $source, int $depth = 0, array $origin = []): ?FlowExecution
    {
        return $this->locked($connection->workspace_id, $connection->id, $contactId, function (AutomationContext $context) use ($version, $threadId, $source, $depth, $origin) {
            abort_unless($version->workspace_id === $context->workspace_id, 404);
            Thread::where('workspace_id', $context->workspace_id)->where('contact_id', $context->contact_id)
                ->where('uazapi_connection_id', $context->uazapi_connection_id)->whereKey($threadId)->firstOrFail();
            if ($this->blockers($context) !== []) {
                return null;
            }
            $execution = $this->create($context, $version, $threadId, $source, $depth, $origin);
            if ($source !== 'sequence') {
                $this->changed($context);
            }

            return $execution;
        });
    }

    private function create(AutomationContext $context, FlowVersion $version, int $threadId, string $source, int $depth, array $origin): FlowExecution
    {
        $start = collect($version->graph['nodes'])->firstWhere('type', 'start');
        abort_unless($start, 422, 'O fluxo publicado não possui início.');
        $execution = FlowExecution::create([
            'workspace_id' => $context->workspace_id, 'flow_id' => $version->flow_id, 'flow_version_id' => $version->id,
            'contact_id' => $context->contact_id, 'thread_id' => $threadId, 'uazapi_connection_id' => $context->uazapi_connection_id,
            'status' => 'running', 'trigger_source' => $source, 'current_node_id' => $start['id'],
            'context' => ['depth' => $depth], 'started_at' => now(),
            'sequence_enrollment_id' => $origin['sequence_enrollment_id'] ?? null,
            'sequence_step_id' => $origin['sequence_step_id'] ?? null, 'continued_from_id' => $origin['continued_from_id'] ?? null,
        ]);
        $context->update(['active_execution_id' => $execution->id]);
        DB::table('flow_events')->insert([
            'workspace_id' => $context->workspace_id, 'flow_execution_id' => $execution->id, 'event_type' => 'started',
            'node_id' => $start['id'], 'metadata' => json_encode(['source' => $source, 'version' => $version->version]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ProcessFlowExecution::dispatch($execution->id)->afterCommit();

        return $execution;
    }

    public function finish(FlowExecution $execution, string $status, ?string $error, string $reason, callable $cleanup): void
    {
        $this->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function (AutomationContext $context) use ($execution, $status, $error, $reason, $cleanup) {
            $execution->refresh();
            if (! in_array($execution->status, self::ACTIVE, true)) {
                return;
            }
            $execution->update(['status' => $status, 'finished_at' => now(), 'error_message' => $error, 'terminal_reason' => $reason]);
            $cleanup();
            $context->update(['active_execution_id' => null]);
            $this->changed($context);
        });
    }

    public function handoff(FlowExecution $execution, FlowVersion $target): void
    {
        $this->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function (AutomationContext $context) use ($execution, $target) {
            $execution->refresh();
            if ($execution->status !== 'running' || $context->active_execution_id !== $execution->id) {
                return;
            }
            abort_unless($target->workspace_id === $execution->workspace_id, 404);
            $execution->update(['status' => 'completed', 'finished_at' => now(), 'terminal_reason' => 'flow_jump']);
            $next = $this->create($context, $target, $execution->thread_id, 'flow_jump', ((int) ($execution->context['depth'] ?? 0)) + 1, [
                'sequence_enrollment_id' => $execution->sequence_enrollment_id,
                'sequence_step_id' => $execution->sequence_step_id, 'continued_from_id' => $execution->id,
            ]);
            if ($execution->sequence_enrollment_id) {
                DB::table('sequence_enrollments')->where('id', $execution->sequence_enrollment_id)
                    ->where('current_step_execution_id', $execution->id)->update(['current_step_execution_id' => $next->id, 'updated_at' => now()]);
            }
            DB::table('flow_events')->insert([
                'workspace_id' => $execution->workspace_id, 'flow_execution_id' => $execution->id, 'event_type' => 'flow_jump',
                'node_id' => $execution->current_node_id, 'metadata' => json_encode(['next_execution_id' => $next->id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function changed(AutomationContext $context): void
    {
        event(new AutomationContextChanged($context));
    }
}
