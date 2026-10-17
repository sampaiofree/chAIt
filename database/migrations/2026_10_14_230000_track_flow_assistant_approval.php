<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_ai_conversations', function (Blueprint $table) {
            $table->foreignId('approved_plan_id')->nullable()->constrained('flow_ai_messages')->nullOnDelete();
        });
        foreach (DB::table('flow_ai_conversations')->whereIn('state', ['building', 'editing', 'paused_on_error'])->get() as $conversation) {
            $purpose = DB::table('flow_ai_runs')->where('conversation_id', $conversation->id)->latest('id')->value('purpose');
            if ($conversation->state === 'paused_on_error' && ! in_array($purpose, ['building', 'editing'])) {
                continue;
            }
            $plan = DB::table('flow_ai_messages')->where('conversation_id', $conversation->id)->where('kind', 'plan')->whereNotNull('approved_at')->whereIn('status', ['approved', 'saved', 'temporary'])->latest('id')->value('id');
            if ($plan) {
                DB::table('flow_ai_conversations')->where('id', $conversation->id)->update(['approved_plan_id' => $plan]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('flow_ai_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_plan_id');
        });
    }
};
