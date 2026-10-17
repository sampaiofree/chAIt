<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_contexts', function (Blueprint $table) {
            $table->index('current_enrollment_id');
            $table->index('active_execution_id');
        });
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->index(['sequence_id', 'status']);
            $table->index(['sequence_version_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->dropIndex(['sequence_id', 'status']);
            $table->dropIndex(['sequence_version_id', 'status']);
        });
        Schema::table('automation_contexts', function (Blueprint $table) {
            $table->dropIndex(['current_enrollment_id']);
            $table->dropIndex(['active_execution_id']);
        });
    }
};
