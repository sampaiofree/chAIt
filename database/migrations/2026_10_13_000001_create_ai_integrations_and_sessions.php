<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_openai_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_key');
            $table->timestamps();
        });

        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('status', 24)->default('active');
            $table->string('model', 64);
            $table->string('remote_conversation_id')->nullable();
            $table->text('cleanup_api_key')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 64)->nullable();
            $table->timestamp('cleanup_completed_at')->nullable();
            $table->timestamps();
            $table->index(['flow_execution_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('ai_session_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_session_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id', 191);
            $table->timestamps();
            $table->unique(['ai_session_id', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_session_inputs');
        Schema::dropIfExists('ai_sessions');
        Schema::dropIfExists('workspace_openai_integrations');
    }
};
