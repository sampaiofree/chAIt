<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->unique(['workspace_id', 'uazapi_connection_id', 'external_chat_id'], 'threads_uazapi_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropUnique('threads_uazapi_external_unique');
        });
    }
};
