<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('access_blocked_at')->nullable();
            $table->string('terms_version')->nullable();
        });
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('secret')->unique();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('code');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('code_expires_at');
            $table->timestamp('sent_at');
            $table->timestamps();
        });
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('origin');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
        Schema::dropIfExists('pending_registrations');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['access_blocked_at', 'terms_version']);
        });
    }
};
