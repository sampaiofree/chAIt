<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_connections', function (Blueprint $table) {
            $table->string('profile_name')->nullable();
            $table->text('profile_pic_url')->nullable();
            $table->timestamp('last_disconnect_at')->nullable();
            $table->string('last_disconnect_reason')->nullable();
            $table->timestamp('provider_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('uazapi_connections', function (Blueprint $table) {
            $table->dropColumn(['profile_name', 'profile_pic_url', 'last_disconnect_at', 'last_disconnect_reason', 'provider_checked_at']);
        });
    }
};
