<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_edit_locks', function (Blueprint $table) {
            $table->foreignId('flow_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('token');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::table('flow_ai_runs', function (Blueprint $table) {
            $table->uuid('edit_token')->nullable();
        });

        $this->transitionExistingFlows();
    }

    public function transitionExistingFlows(): void
    {
        // An active flow keeps the graph currently in production, not its unpublished edits.
        DB::table('flows')->where('is_active', true)->whereNotNull('published_version_id')
            ->orderBy('id')->each(function ($flow): void {
                $graph = DB::table('flow_versions')->where('id', $flow->published_version_id)->value('graph');
                if ($graph !== null) {
                    DB::table('flows')->where('id', $flow->id)->update(['graph' => $graph, 'revision' => $flow->revision + 1]);
                }
            });
        DB::table('flow_ai_runs')->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelled', 'finished_at' => now(), 'provider_items' => null]);
        DB::table('flow_ai_messages')->whereIn('status', ['temporary', 'approved', 'pending'])->update(['status' => 'superseded']);
        DB::table('flow_ai_conversations')->update(['state' => 'briefing', 'approved_plan_id' => null, 'updated_at' => now()]);
        DB::table('flow_draft_sessions')->delete();
    }

    public function down(): void
    {
        Schema::table('flow_ai_runs', fn (Blueprint $table) => $table->dropColumn('edit_token'));
        Schema::dropIfExists('flow_edit_locks');
    }
};
