<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uazapi_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('instance_id')->nullable()->unique();
            $table->string('instance_name')->unique();
            $table->text('instance_token')->nullable();
            $table->string('status', 32)->default('disconnected');
            $table->string('webhook_secret_hash', 64)->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });

        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->boolean('can_view_unassigned')->default(false);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->string('cpf', 14)->nullable();
            $table->text('notes')->nullable();
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->foreignId('phone_number_id')->nullable()->change();
            $table->foreignId('uazapi_connection_id')->nullable()->after('workspace_id')
                ->constrained('uazapi_connections')->cascadeOnDelete();
            $table->string('external_chat_id')->nullable();
            $table->foreignId('last_assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('human_opened_at')->nullable();
        });

        Schema::create('uazapi_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uazapi_connection_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64)->nullable();
            $table->string('event_id', 191)->nullable();
            $table->string('dedupe_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uazapi_webhook_receipts');
        Schema::table('threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uazapi_connection_id');
            $table->dropConstrainedForeignId('last_assigned_to_user_id');
            $table->dropColumn(['external_chat_id', 'finished_at', 'human_opened_at']);
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['email', 'cpf', 'notes']);
        });
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->dropColumn('can_view_unassigned');
        });
        Schema::dropIfExists('uazapi_connections');
    }
};
