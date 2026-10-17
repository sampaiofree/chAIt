<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_connections', function (Blueprint $table) {
            $table->timestamp('chat_last_reconciled_at')->nullable();
            $table->timestamp('chat_last_webhook_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('uazapi_connections', fn (Blueprint $table) => $table->dropColumn(['chat_last_reconciled_at', 'chat_last_webhook_at']));
    }
};
