<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('graph');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['flow_id', 'version']);
        });

        Schema::table('flows', function (Blueprint $table) {
            $table->foreignId('published_version_id')->nullable()->after('revision')->constrained('flow_versions')->nullOnDelete();
            $table->boolean('is_active')->default(false)->after('published_version_id');
        });

        Schema::create('flow_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 160);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->index(['flow_id', 'workspace_id']);
        });

        Schema::create('contact_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 24)->default('text');
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('contact_custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('contact_custom_fields')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['contact_id', 'field_id']);
        });

        Schema::create('contact_tag', function (Blueprint $table) {
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['contact_id', 'tag_id']);
            $table->index(['workspace_id', 'tag_id']);
        });

        DB::table('tag_thread')->join('threads', 'threads.id', '=', 'tag_thread.thread_id')
            ->select('threads.workspace_id', 'threads.contact_id', 'tag_thread.tag_id')
            ->orderBy('threads.id')->get()->each(function ($row): void {
                DB::table('contact_tag')->updateOrInsert(
                    ['contact_id' => $row->contact_id, 'tag_id' => $row->tag_id],
                    ['workspace_id' => $row->workspace_id, 'created_at' => now(), 'updated_at' => now()],
                );
            });

        Schema::create('flow_trigger_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('welcome_flow_id')->nullable()->constrained('flows')->nullOnDelete();
            $table->foreignId('default_flow_id')->nullable()->constrained('flows')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('flow_keyword_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->string('term', 191);
            $table->string('operator', 24);
            $table->boolean('interrupt_active')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['uazapi_connection_id', 'position']);
        });

        Schema::create('flow_first_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_inbound_at');
            $table->timestamps();
            $table->unique(['uazapi_connection_id', 'contact_id']);
        });

        Schema::create('flow_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('running');
            $table->string('trigger_source', 32);
            $table->string('current_node_id', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'uazapi_connection_id', 'status']);
        });

        Schema::create('flow_waits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_execution_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->foreignId('field_id')->constrained('contact_custom_fields')->cascadeOnDelete();
            $table->string('status', 24)->default('waiting');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('automation_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 24);
            $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_execution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'uazapi_connection_id', 'released_at', 'expires_at'], 'automation_pause_lookup');
        });

        Schema::create('flow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_execution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 48);
            $table->string('node_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'flow_execution_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_events');
        Schema::dropIfExists('automation_pauses');
        Schema::dropIfExists('flow_waits');
        Schema::dropIfExists('flow_executions');
        Schema::dropIfExists('flow_first_contacts');
        Schema::dropIfExists('flow_keyword_triggers');
        Schema::dropIfExists('flow_trigger_settings');
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('contact_custom_field_values');
        Schema::dropIfExists('contact_custom_fields');
        Schema::dropIfExists('flow_assets');
        Schema::table('flows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_version_id');
            $table->dropColumn('is_active');
        });
        Schema::dropIfExists('flow_versions');
    }
};
