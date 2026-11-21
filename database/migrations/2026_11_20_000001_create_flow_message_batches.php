<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_inbox', function (Blueprint $table) {
            $table->timestamp('accepted_at', 3)->nullable();
        });

        Schema::create('flow_message_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_wait_id')->nullable()->constrained('flow_waits')->cascadeOnDelete();
            $table->foreignId('ai_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->json('entries');
            $table->timestamp('due_at', 3);
            $table->timestamp('cutoff_at', 3);
            $table->boolean('sealed')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
            $table->index('due_at');
            $table->index(['ai_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_message_batches');
        Schema::table('uazapi_inbox', function (Blueprint $table) {
            $table->dropColumn('accepted_at');
        });
    }
};
