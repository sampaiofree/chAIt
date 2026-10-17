<?php

namespace App\Services\Sequences;

use App\Models\AutomationContext;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogService;
use App\Services\Flows\FlowExecutionManager;
use Illuminate\Support\Facades\DB;

class SequenceEnrollmentService
{
    public function enroll(Thread $thread, Sequence $sequence, string $source, string $idempotencyKey, ?User $actor = null): array
    {
        abort_unless($thread->workspace_id === $sequence->workspace_id && $thread->uazapi_connection_id, 404);

        return app(FlowExecutionManager::class)->locked($thread->workspace_id, $thread->uazapi_connection_id, $thread->contact_id,
            function (AutomationContext $context) use ($thread, $sequence, $source, $idempotencyKey, $actor): array {
                $request = DB::table('sequence_enrollment_requests')->where('workspace_id', $thread->workspace_id)->where('idempotency_key', $idempotencyKey)->first();
                if ($request) {
                    $previous = SequenceEnrollment::findOrFail($request->enrollment_id);
                    abort_unless($previous->contact_id === $thread->contact_id && $previous->uazapi_connection_id === $thread->uazapi_connection_id && $previous->sequence_id === $sequence->id, 409, 'Chave de inclusão já utilizada para outra operação.');

                    return ['result' => $request->result, 'enrollment' => $previous];
                }
                $sequence = Sequence::where('workspace_id', $thread->workspace_id)->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
                $current = SequenceEnrollment::whereKey($context->current_enrollment_id)->lockForUpdate()->first();
                if ($current && ! in_array($current->status, SequenceEnrollment::TERMINAL, true) && $current->sequence_id === $sequence->id) {
                    $this->remember($thread->workspace_id, $idempotencyKey, $current, 'already_enrolled');

                    return ['result' => 'already_enrolled', 'enrollment' => $current];
                }
                abort_unless($sequence->is_active && $sequence->published_version_id, 422, 'A sequência precisa estar publicada e ativa.');
                $step = $sequence->publishedVersion->steps()->firstOrFail();
                $enrollment = SequenceEnrollment::create([
                    'workspace_id' => $thread->workspace_id, 'uazapi_connection_id' => $thread->uazapi_connection_id,
                    'contact_id' => $thread->contact_id, 'thread_id' => $thread->id, 'sequence_id' => $sequence->id,
                    'sequence_version_id' => $sequence->published_version_id, 'status' => 'waiting_current_flow',
                    'next_step_id' => $step->id, 'source' => $source, 'created_by_user_id' => $actor?->id, 'enrolled_at' => now(),
                ]);
                if ($current && ! in_array($current->status, SequenceEnrollment::TERMINAL, true)) {
                    $current->update(['status' => 'replaced', 'next_step_id' => null, 'due_at' => null, 'running_since' => null,
                        'remaining_seconds' => null, 'blocked_reasons' => [], 'generation' => $current->generation + 1,
                        'replaced_by_id' => $enrollment->id, 'finished_at' => now(), 'terminal_reason' => 'replaced']);
                    $this->audit($current, 'replaced', ['replacement_id' => $enrollment->id], $actor);
                }
                $context->update(['current_enrollment_id' => $enrollment->id]);
                $this->remember($thread->workspace_id, $idempotencyKey, $enrollment, 'enrolled');
                $this->audit($enrollment, 'enrolled', ['source' => $source], $actor);
                app(FlowExecutionManager::class)->changed($context);

                return ['result' => 'enrolled', 'enrollment' => $enrollment->refresh()];
            });
    }

    private function remember(int $workspaceId, string $key, SequenceEnrollment $enrollment, string $result): void
    {
        DB::table('sequence_enrollment_requests')->insert(['workspace_id' => $workspaceId, 'idempotency_key' => $key,
            'enrollment_id' => $enrollment->id, 'result' => $result, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function audit(SequenceEnrollment $enrollment, string $action, array $metadata = [], ?User $actor = null): void
    {
        app(AuditLogService::class)->record(Workspace::findOrFail($enrollment->workspace_id), 'sequence.'.$action, $actor, $enrollment,
            payload: array_merge(['sequence_id' => $enrollment->sequence_id, 'version_id' => $enrollment->sequence_version_id,
                'generation' => $enrollment->generation, 'status' => $enrollment->status], $metadata));
    }
}
