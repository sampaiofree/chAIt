<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
        });

        Schema::table('flows', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('workspace_id')->constrained('flow_folders')->nullOnDelete();
            $table->index(['workspace_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'folder_id']);
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('flow_folders');
    }
};
