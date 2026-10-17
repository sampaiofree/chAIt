<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_folders', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'name']);
            $table->foreignId('parent_id')->nullable()->after('workspace_id')->constrained('flow_folders')->nullOnDelete();
            $table->index(['workspace_id', 'parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('flow_folders', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'parent_id', 'name']);
            $table->dropConstrainedForeignId('parent_id');
            $table->unique(['workspace_id', 'name']);
        });
    }
};
