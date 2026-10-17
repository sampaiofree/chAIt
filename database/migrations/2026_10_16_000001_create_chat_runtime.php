<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->string('last_message_id', 191)->nullable();
            $table->string('last_message_type', 64)->nullable();
            $table->string('last_message_direction', 16)->nullable();
            $table->text('last_message_preview')->nullable();
            $table->unsignedBigInteger('chat_revision')->default(0);
            $table->index(['workspace_id', 'uazapi_connection_id', 'last_message_at', 'id'], 'threads_chat_page');
        });
        Schema::table('uazapi_connections', function (Blueprint $table) {
            $table->string('chat_import_status', 20)->default('not_started');
            $table->unsignedInteger('chat_import_offset')->default(0);
            $table->unsignedSmallInteger('chat_import_attempts')->default(0);
            $table->timestamp('chat_import_dispatched_at')->nullable();
        });
        Schema::create('uazapi_inbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('chat_id', 191)->default('');
            $table->string('dedupe_hash', 64)->unique();
            $table->longText('payload')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('consumers')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['uazapi_connection_id', 'chat_id', 'status', 'id'], 'inbox_contact_order');
            $table->index(['status', 'dispatched_at']);
        });
        Schema::create('chat_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_execution_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('read_attempts')->default(0);
            $table->string('kind', 24);
            $table->longText('payload')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('track_id', 191)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['uazapi_connection_id', 'track_id', 'kind'], 'chat_operation_intent');
            $table->index(['status', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_operations');
        Schema::dropIfExists('uazapi_inbox');
        Schema::table('uazapi_connections', fn (Blueprint $table) => $table->dropColumn(['chat_import_status', 'chat_import_offset', 'chat_import_attempts', 'chat_import_dispatched_at']));
        Schema::table('threads', function (Blueprint $table) {
            $table->dropIndex('threads_chat_page');
            $table->dropColumn(['last_message_id', 'last_message_type', 'last_message_direction', 'last_message_preview', 'chat_revision']);
        });
    }
};
