<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_menu_waits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_execution_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->uuid('selection_token')->unique();
            $table->string('status', 24)->default('sending');
            $table->string('effective_format', 16);
            $table->unsignedSmallInteger('invalid_count')->default(0);
            $table->string('selected_option_id', 48)->nullable();
            $table->json('transport')->nullable();
            $table->json('retry_queue');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['flow_execution_id', 'node_id']);
            $table->index(['status', 'expires_at']);
        });
        Schema::create('flow_menu_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_menu_wait_id')->constrained('flow_menu_waits')->cascadeOnDelete();
            $table->string('message_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['flow_menu_wait_id', 'message_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_menu_receipts');
        Schema::dropIfExists('flow_menu_waits');
    }
};
