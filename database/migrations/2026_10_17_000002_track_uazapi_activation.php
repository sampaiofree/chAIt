<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_connections', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable();
        });

        // Existing installations start accepting new messages at this rollout,
        // without replaying the provider's earlier conversations.
        DB::table('uazapi_connections')->whereNotNull('instance_token')->update(['activated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('uazapi_connections', fn (Blueprint $table) => $table->dropColumn('activated_at'));
    }
};
