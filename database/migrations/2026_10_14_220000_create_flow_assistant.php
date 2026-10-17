<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->boolean('can_use_flow_assistant')->default(false);
        });
        Schema::create('flow_draft_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('generation');
            $table->unsignedInteger('base_revision');
            $table->unsignedInteger('revision')->default(1);
            $table->string('name', 120);
            $table->json('graph');
            $table->timestamps();
        });
        Schema::create('flow_ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('model')->default('auto');
            $table->string('state')->default('briefing');
            $table->text('briefing')->nullable();
            $table->timestamps();
        });
        Schema::create('flow_ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('flow_ai_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role');
            $table->text('content');
            $table->string('kind')->default('message');
            $table->string('status')->nullable();
            $table->uuid('generation')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('flow_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('flow_ai_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('generation');
            $table->string('model');
            $table->string('status')->default('queued');
            $table->string('purpose');
            $table->string('credential_fingerprint', 64)->nullable();
            $table->foreignId('message_id')->nullable()->constrained('flow_ai_messages')->nullOnDelete();
            $table->json('provider_items')->nullable();
            $table->unsignedInteger('cycles')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'status']);
        });
        Schema::create('flow_ai_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('flow_ai_runs')->cascadeOnDelete();
            $table->string('call_id', 128);
            $table->string('name');
            $table->string('fingerprint', 64);
            $table->json('result');
            $table->unsignedInteger('draft_revision')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'call_id']);
        });
    }

    public function down(): void
    {
        foreach (['flow_ai_operations', 'flow_ai_runs', 'flow_ai_messages', 'flow_ai_conversations', 'flow_draft_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('workspace_memberships', fn (Blueprint $table) => $table->dropColumn('can_use_flow_assistant'));
    }
};
