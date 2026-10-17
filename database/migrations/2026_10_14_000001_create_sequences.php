<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('sequence_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->string('timezone', 64);
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['sequence_id', 'version']);
        });
        Schema::table('sequences', function (Blueprint $table) {
            $table->foreign('published_version_id')->references('id')->on('sequence_versions')->restrictOnDelete();
        });
        Schema::create('sequence_version_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->restrictOnDelete();
            $table->foreignId('flow_version_id')->constrained()->restrictOnDelete();
            $table->unique(['sequence_version_id', 'flow_id']);
        });
        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('schedule_type', 24);
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('flow_version_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['sequence_version_id', 'position']);
        });
        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_id')->constrained()->restrictOnDelete();
            $table->foreignId('sequence_version_id')->constrained()->restrictOnDelete();
            $table->string('status', 32);
            $table->foreignId('next_step_id')->nullable()->constrained('sequence_steps')->restrictOnDelete();
            $table->foreignId('current_step_execution_id')->nullable()->constrained('flow_executions')->nullOnDelete();
            $table->unsignedInteger('remaining_seconds')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('running_since')->nullable();
            $table->json('blocked_reasons')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->string('source', 32);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->string('terminal_reason', 64)->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
            $table->index(['workspace_id', 'contact_id']);
        });
        Schema::create('automation_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_execution_id')->nullable()->constrained('flow_executions')->nullOnDelete();
            $table->foreignId('current_enrollment_id')->nullable()->constrained('sequence_enrollments')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'uazapi_connection_id', 'contact_id'], 'automation_context_unique');
        });
        Schema::create('sequence_enrollment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 160);
            $table->foreignId('enrollment_id')->constrained('sequence_enrollments')->cascadeOnDelete();
            $table->string('result', 32);
            $table->timestamps();
            $table->unique(['workspace_id', 'idempotency_key']);
        });
        Schema::table('flow_executions', function (Blueprint $table) {
            $table->foreignId('sequence_enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sequence_step_id')->nullable()->constrained('sequence_steps')->restrictOnDelete();
            $table->foreignId('continued_from_id')->nullable()->constrained('flow_executions')->nullOnDelete();
            $table->string('terminal_reason', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flow_executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sequence_enrollment_id');
            $table->dropConstrainedForeignId('sequence_step_id');
            $table->dropConstrainedForeignId('continued_from_id');
            $table->dropColumn('terminal_reason');
        });
        Schema::dropIfExists('sequence_enrollment_requests');
        Schema::dropIfExists('automation_contexts');
        Schema::dropIfExists('sequence_enrollments');
        Schema::dropIfExists('sequence_steps');
        Schema::dropIfExists('sequence_version_flows');
        Schema::table('sequences', fn (Blueprint $table) => $table->dropForeign(['published_version_id']));
        Schema::dropIfExists('sequence_versions');
        Schema::dropIfExists('sequences');
    }
};
