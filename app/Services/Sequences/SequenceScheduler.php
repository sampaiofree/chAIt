<?php

namespace App\Services\Sequences;

use App\Jobs\ExpireAiSession;
use App\Jobs\ExpireFlowWait;
use App\Jobs\ProcessFlowExecution;
use App\Jobs\ProcessSequenceStep;
use App\Models\AiSession;
use App\Models\AutomationContext;
use App\Models\FlowExecution;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\UazapiConnection;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Flows\FlowMenuRuntime;
use Illuminate\Support\Facades\DB;

class SequenceScheduler
{
    public function reconcile(AutomationContext $context): ?SequenceEnrollment
    {
        return app(FlowExecutionManager::class)->locked($context->workspace_id, $context->uazapi_connection_id, $context->contact_id,
            fn ($locked) => $this->reconcileLocked($locked));
    }

    public function reconcileLocked(AutomationContext $context): ?SequenceEnrollment
    {
        $enrollment = SequenceEnrollment::whereKey($context->current_enrollment_id)->lockForUpdate()->first();
        if (! $enrollment || in_array($enrollment->status, SequenceEnrollment::TERMINAL, true)) {
            return null;
        }
        abort_unless($enrollment->workspace_id === $context->workspace_id && $enrollment->uazapi_connection_id === $context->uazapi_connection_id && $enrollment->contact_id === $context->contact_id, 409, 'O contexto da participação é inválido.');
        $anchor = now();
        if ($enrollment->current_step_execution_id) {
            $execution = FlowExecution::find($enrollment->current_step_execution_id);
            if ($execution && in_array($execution->status, FlowExecutionManager::ACTIVE, true)) {
                $this->recoverExecution($execution);

                return $enrollment;
            }
            if (! $execution || $execution->status === 'failed') {
                return $this->terminate($context, $enrollment, 'failed', $execution?->terminal_reason ?? 'execution_missing');
            }
            // Handoffs update this pointer in the same transaction. A jump never advances the step.
            if ($execution->terminal_reason === 'flow_jump') {
                return $enrollment;
            }
            $step = $enrollment->nextStep;
            $next = SequenceStep::where('sequence_version_id', $enrollment->sequence_version_id)->where('position', $step->position + 1)->first();
            app(SequenceEnrollmentService::class)->audit($enrollment, 'step_finished', ['execution_id' => $execution->id, 'reason' => $execution->terminal_reason]);
            if (! $next) {
                return $this->terminate($context, $enrollment, 'completed', $execution->terminal_reason ?? 'completed');
            }
            $anchor = $execution->finished_at ?? now();
            $enrollment->update(['next_step_id' => $next->id, 'current_step_execution_id' => null, 'status' => 'waiting_current_flow',
                'due_at' => null, 'remaining_seconds' => null, 'running_since' => null, 'generation' => $enrollment->generation + 1]);
            $enrollment->unsetRelation('nextStep');
        }
        $step = $enrollment->nextStep;
        if (! $step || $step->sequence_version_id !== $enrollment->sequence_version_id) {
            return $this->terminate($context, $enrollment, 'failed', 'invalid_step');
        }
        $blockers = app(FlowExecutionManager::class)->blockers($context);
        $previous = [$enrollment->status, $enrollment->blocked_reasons ?? []];
        $relative = $step->schedule_type === 'interval';
        if (! $relative) {
            $enrollment->due_at = $step->scheduled_at;
        }
        if ($blockers !== []) {
            if ($relative && $enrollment->running_since !== null) {
                $enrollment->remaining_seconds = max(0, (int) ceil(now()->diffInSeconds($enrollment->due_at, false)));
                $enrollment->running_since = null;
                $enrollment->due_at = null;
                $enrollment->generation++;
            }
            $enrollment->status = $enrollment->remaining_seconds === null && in_array('flow', $blockers, true) ? 'waiting_current_flow' : 'blocked';
            $enrollment->blocked_reasons = $blockers;
        } else {
            if ($relative && $enrollment->running_since === null) {
                $enrollment->remaining_seconds ??= $step->delay_seconds;
                $enrollment->running_since = $anchor;
                $enrollment->due_at = $anchor->copy()->addSeconds($enrollment->remaining_seconds);
                $enrollment->generation++;
            }
            $enrollment->blocked_reasons = [];
            $enrollment->status = now()->gte($enrollment->due_at) ? 'ready' : 'waiting_time';
        }
        if ($enrollment->isDirty()) {
            $enrollment->save();
            if ($previous !== [$enrollment->status, $enrollment->blocked_reasons]) {
                app(SequenceEnrollmentService::class)->audit($enrollment, 'state_changed', ['blockers' => $enrollment->blocked_reasons]);
            }
        }

        return $enrollment;
    }

    private function recoverExecution(FlowExecution $execution): void
    {
        if ($execution->updated_at->gt(now()->subMinutes(2))) {
            return;
        }
        if ($execution->status === 'running') {
            $resumeAt = $execution->context['resume_at'] ?? null;
            if (! $resumeAt || now()->gte($resumeAt)) {
                ProcessFlowExecution::dispatch($execution->id)->afterCommit();
            }
        } elseif ($execution->status === 'waiting_reply') {
            $wait = DB::table('flow_waits')->where('flow_execution_id', $execution->id)->where('status', 'waiting')->where('expires_at', '<=', now())->first();
            if ($wait) {
                ExpireFlowWait::dispatch($wait->id)->afterCommit();
            }
        } elseif ($execution->status === 'waiting_menu') {
            app(FlowMenuRuntime::class)->recoverExecution($execution->id);
        } elseif ($execution->status === 'waiting_ai') {
            $session = AiSession::where('flow_execution_id', $execution->id)->where('status', 'active')->where('expires_at', '<=', now())->first();
            if ($session) {
                ExpireAiSession::dispatch($session->id)->afterCommit();
            }
        }
    }

    private function terminate(AutomationContext $context, SequenceEnrollment $enrollment, string $status, string $reason): SequenceEnrollment
    {
        $enrollment->update(['status' => $status, 'terminal_reason' => $reason, 'finished_at' => now(), 'next_step_id' => null,
            'due_at' => null, 'running_since' => null, 'remaining_seconds' => null, 'blocked_reasons' => [], 'generation' => $enrollment->generation + 1]);
        $context->update(['current_enrollment_id' => null]);
        app(SequenceEnrollmentService::class)->audit($enrollment, $status, ['reason' => $reason]);

        return $enrollment;
    }

    public function execute(int $enrollmentId, int $generation): void
    {
        $enrollment = SequenceEnrollment::find($enrollmentId);
        if (! $enrollment) {
            return;
        }
        app(FlowExecutionManager::class)->locked($enrollment->workspace_id, $enrollment->uazapi_connection_id, $enrollment->contact_id,
            function (AutomationContext $context) use ($enrollmentId, $generation): void {
                $current = SequenceEnrollment::find($enrollmentId);
                if ($context->current_enrollment_id !== $enrollmentId || $current->generation !== $generation || in_array($current->status, SequenceEnrollment::TERMINAL, true)) {
                    return;
                }
                $current = $this->reconcileLocked($context);
                if (! $current || $current->status !== 'ready') {
                    return;
                }
                $connection = UazapiConnection::findOrFail($context->uazapi_connection_id);
                $step = $current->nextStep;
                $execution = app(FlowExecutionManager::class)->start($step->flowVersion, $context->contact_id, $current->thread_id, $connection, 'sequence', origin: [
                    'sequence_enrollment_id' => $current->id, 'sequence_step_id' => $step->id,
                ]);
                if (! $execution) {
                    return;
                }
                $current->update(['status' => 'running_step', 'current_step_execution_id' => $execution->id,
                    'due_at' => null, 'running_since' => null, 'remaining_seconds' => null, 'generation' => $current->generation + 1]);
                app(SequenceEnrollmentService::class)->audit($current, 'step_started', ['execution_id' => $execution->id, 'step_id' => $step->id]);
            });
    }

    public function tick(): int
    {
        $count = 0;
        SequenceEnrollment::whereNotIn('status', SequenceEnrollment::TERMINAL)
            ->where(fn ($query) => $query->where('status', '<>', 'waiting_time')->orWhere('due_at', '<=', now()))
            ->orderBy('id')->chunkById(100, function ($enrollments) use (&$count): void {
                foreach ($enrollments as $enrollment) {
                    $context = AutomationContext::where('current_enrollment_id', $enrollment->id)->first();
                    if (! $context) {
                        continue;
                    }
                    $current = $this->reconcile($context);
                    if ($current?->status === 'ready') {
                        ProcessSequenceStep::dispatch($current->id, $current->generation)->afterCommit();
                        $count++;
                    }
                }
            });

        return $count;
    }
}
