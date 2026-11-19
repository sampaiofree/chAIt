<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->timestamp('reset_at', 6);
            $table->unique(['workspace_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_resets');
    }
};
