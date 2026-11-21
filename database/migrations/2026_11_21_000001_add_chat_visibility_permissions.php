<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->boolean('can_view_own')->default(true);
            $table->boolean('can_view_all')->default(false);
        });
        DB::table('workspace_memberships')->where('can_view_unassigned', true)->update(['can_view_all' => true]);
    }

    public function down(): void
    {
        Schema::table('workspace_memberships', fn (Blueprint $table) => $table->dropColumn(['can_view_own', 'can_view_all']));
    }
};
