<?php

namespace App\Services\Flows;

use App\Models\FlowExecution;
use App\Models\Thread;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class FlowAutomationControl
{
    public function pause(Thread $thread, int $userId, ?CarbonInterface $expiresAt): void
    {
        DB::transaction(function () use ($thread, $userId, $expiresAt): void {
            $thread->contact()->lockForUpdate()->firstOrFail();
            $pauses = DB::table('automation_pauses')->where('uazapi_connection_id', $thread->uazapi_connection_id)
                ->where('contact_id', $thread->contact_id)->where('reason', 'manual')->whereNull('released_at');
            abort_if((clone $pauses)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists(), 409, 'A automação já está pausada para este contato.');
            $pauses->whereNotNull('expires_at')->where('expires_at', '<=', now())
                ->update(['released_at' => now(), 'updated_at' => now()]);

            DB::table('automation_pauses')->insert([
                'workspace_id' => $thread->workspace_id, 'uazapi_connection_id' => $thread->uazapi_connection_id,
                'contact_id' => $thread->contact_id, 'thread_id' => $thread->id, 'reason' => 'manual',
                'created_by_user_id' => $userId, 'expires_at' => $expiresAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $active = FlowExecution::query()->where('contact_id', $thread->contact_id)
                ->where('uazapi_connection_id', $thread->uazapi_connection_id)
                ->whereIn('status', ['running', 'waiting_reply', 'waiting_ai', 'waiting_menu'])->lockForUpdate()->get();
            foreach ($active as $execution) {
                app(FlowRuntime::class)->finish($execution, 'interrupted', 'Automação pausada manualmente.');
            }
            $this->changed($thread);
        });
    }

    public function humanOpened(Thread $thread): void
    {
        $connectionId = $thread->uazapi_connection_id;
        if (! $connectionId) {
            return;
        }
        DB::transaction(function () use ($thread, $connectionId): void {
            $thread->contact()->lockForUpdate()->firstOrFail();
            $active = FlowExecution::query()->where('contact_id', $thread->contact_id)->where('uazapi_connection_id', $connectionId)
                ->whereIn('status', ['running', 'waiting_reply', 'waiting_ai', 'waiting_menu'])->lockForUpdate()->get();
            foreach ($active as $execution) {
                app(FlowRuntime::class)->finish($execution, 'interrupted', 'Atendimento humano iniciado.');
            }
            $exists = DB::table('automation_pauses')->where('uazapi_connection_id', $connectionId)->where('contact_id', $thread->contact_id)
                ->where('reason', 'human')->whereNull('released_at')->exists();
            if (! $exists) {
                DB::table('automation_pauses')->insert([
                    'workspace_id' => $thread->workspace_id, 'uazapi_connection_id' => $connectionId, 'contact_id' => $thread->contact_id,
                    'thread_id' => $thread->id, 'reason' => 'human', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->changed($thread);
        });
    }

    public function humanFinished(Thread $thread): void
    {
        if (! $thread->uazapi_connection_id) {
            return;
        }
        DB::transaction(function () use ($thread): void {
            $thread->contact()->lockForUpdate()->firstOrFail();
            DB::table('automation_pauses')->where('uazapi_connection_id', $thread->uazapi_connection_id)->where('contact_id', $thread->contact_id)
                ->where('reason', 'human')->whereNull('released_at')->update(['released_at' => now(), 'updated_at' => now()]);
            $this->changed($thread);
        });
    }

    public function resume(Thread $thread): void
    {
        if (! $thread->uazapi_connection_id) {
            return;
        }
        DB::transaction(function () use ($thread): void {
            $thread->contact()->lockForUpdate()->firstOrFail();
            DB::table('automation_pauses')->where('uazapi_connection_id', $thread->uazapi_connection_id)->where('contact_id', $thread->contact_id)
                ->where('reason', 'manual')->whereNull('released_at')->update(['released_at' => now(), 'updated_at' => now()]);
            $this->changed($thread);
        });
    }

    private function changed(Thread $thread): void
    {
        if ($thread->uazapi_connection_id) {
            app(FlowExecutionManager::class)->locked($thread->workspace_id, $thread->uazapi_connection_id, $thread->contact_id,
                fn ($context) => app(FlowExecutionManager::class)->changed($context));
        }
    }
}
