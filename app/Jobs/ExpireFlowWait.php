<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\FlowExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ExpireFlowWait implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $waitId)
    {
        $this->onQueue('automations');
    }

    public function handle(): void
    {
        $executionId = DB::transaction(function (): ?int {
            $executionId = DB::table('flow_waits')->where('id', $this->waitId)->value('flow_execution_id');
            if (! $executionId) {
                return null;
            }
            $contactId = FlowExecution::whereKey($executionId)->value('contact_id');
            Contact::whereKey($contactId)->lockForUpdate()->first();
            $execution = FlowExecution::query()->whereKey($executionId)->lockForUpdate()->first();
            if (! $execution || $execution->status !== 'waiting_reply') {
                return null;
            }
            $wait = DB::table('flow_waits')->where('id', $this->waitId)->lockForUpdate()->first();
            if (! $wait || $wait->status !== 'waiting' || now()->lt($wait->expires_at)) {
                return null;
            }
            DB::table('flow_waits')->where('id', $wait->id)->update(['status' => 'timed_out', 'completed_at' => now(), 'updated_at' => now()]);
            $context = $execution->context ?? [];
            $context['resume_handle'] = 'not_replied';
            $execution->forceFill(['status' => 'running', 'context' => $context])->save();

            return $execution->id;
        });
        if ($executionId) {
            ProcessFlowExecution::dispatch($executionId)->afterCommit();
        }
    }
}
