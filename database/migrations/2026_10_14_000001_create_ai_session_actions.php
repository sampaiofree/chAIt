<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_session_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_session_id')->constrained()->cascadeOnDelete();
            $table->string('call_id', 128);
            $table->string('action', 64);
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('result', 32);
            $table->timestamps();
            $table->unique(['ai_session_id', 'call_id']);
        });
        Schema::create('ai_session_media_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_session_id')->constrained()->cascadeOnDelete();
            // Keep the reference after asset removal so it cannot reset an attempted send.
            $table->unsignedBigInteger('asset_id');
            $table->string('track_id', 128);
            $table->string('result', 32)->default('pending');
            $table->timestamps();
            $table->unique(['ai_session_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_session_media_attempts');
        Schema::dropIfExists('ai_session_actions');
    }
};
