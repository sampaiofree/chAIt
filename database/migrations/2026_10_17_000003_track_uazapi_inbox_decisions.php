<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_inbox', function (Blueprint $table) {
            $table->string('decision', 40)->nullable();
            $table->unsignedInteger('delivery_count')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('uazapi_inbox', fn (Blueprint $table) => $table->dropColumn(['decision', 'delivery_count']));
    }
};
