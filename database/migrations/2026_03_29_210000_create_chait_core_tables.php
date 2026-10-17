<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default(config('app.timezone', 'UTC'));
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_workspace_id')->nullable()->after('remember_token')
                ->constrained('workspaces')->nullOnDelete();
        });

        Schema::create('whatsapp_business_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('meta_waba_id')->unique();
            $table->string('name')->nullable();
            $table->string('currency', 16)->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('onboarding_mode', 32)->default('new');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_business_account_id')->constrained()->cascadeOnDelete();
            $table->string('meta_phone_number_id')->unique();
            $table->string('display_phone_number');
            $table->string('verified_name')->nullable();
            $table->string('quality_rating', 32)->nullable();
            $table->string('code_verification_status', 32)->nullable();
            $table->string('platform_type', 32)->nullable();
            $table->string('throughput_level', 32)->nullable();
            $table->string('connection_status', 32)->default('draft');
            $table->string('onboarding_mode', 32)->default('new');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_customer_activity_at')->nullable();
            $table->timestamp('customer_service_window_expires_at')->nullable();
            $table->timestamp('free_entry_point_expires_at')->nullable();
            $table->string('last_pricing_type', 64)->nullable();
            $table->string('last_pricing_category', 64)->nullable();
            $table->json('webhook_overrides')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('connection_onboarding_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('phone_number_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->string('onboarding_mode', 32)->default('new');
            $table->string('meta_configuration_id')->nullable();
            $table->string('frontend_session_id')->nullable();
            $table->string('meta_code')->nullable();
            $table->string('meta_waba_id')->nullable();
            $table->string('meta_phone_number_id')->nullable();
            $table->string('business_token_last4', 4)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('exchanged_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('connection_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_business_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('phone_number_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_type', 32);
            $table->text('access_token');
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('connection_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phone_number_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('quality_rating', 32)->nullable();
            $table->string('throughput_level', 32)->nullable();
            $table->string('review_status', 32)->nullable();
            $table->string('billing_status', 32)->nullable();
            $table->json('notes')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
        });

        Schema::create('connection_sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_business_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('phone_number_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_type', 64);
            $table->string('status', 32)->default('pending');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('ran_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_business_account_id')->constrained()->cascadeOnDelete();
            $table->string('meta_template_id')->nullable();
            $table->string('name');
            $table->string('language', 32);
            $table->string('category', 32);
            $table->string('status', 32)->default('draft');
            $table->string('quality_score', 32)->nullable();
            $table->json('components')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_business_account_id', 'name', 'language']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('profile')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->default('whatsapp');
            $table->string('external_id');
            $table->string('handle')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'type', 'external_id']);
        });

        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phone_number_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 32)->default('whatsapp');
            $table->string('status', 32)->default('open');
            $table->string('priority', 32)->default('normal');
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamp('customer_service_window_expires_at')->nullable();
            $table->timestamp('free_entry_point_expires_at')->nullable();
            $table->string('last_pricing_type', 64)->nullable();
            $table->string('last_pricing_category', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['phone_number_id', 'contact_id', 'channel']);
        });

        Schema::create('thread_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 32)->default('active');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phone_number_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 32);
            $table->string('message_type', 32);
            $table->string('lifecycle_status', 32);
            $table->string('biz_opaque_callback_data')->nullable()->unique();
            $table->string('whatsapp_message_id')->nullable()->unique();
            $table->string('pricing_type', 64)->nullable();
            $table->string('pricing_category', 64)->nullable();
            $table->boolean('billable')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('played_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('error_code')->nullable();
            $table->string('error_title')->nullable();
            $table->text('error_details')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('message_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->unsignedInteger('position')->default(0);
            $table->text('content')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('message_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('phone_number_id')->nullable()->constrained()->nullOnDelete();
            $table->string('whatsapp_message_id')->nullable();
            $table->string('status', 32);
            $table->string('recipient_id')->nullable();
            $table->string('checksum', 64)->unique();
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('message_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('phone_number_id')->nullable()->constrained()->nullOnDelete();
            $table->string('whatsapp_message_id')->nullable();
            $table->unsignedInteger('error_code')->nullable();
            $table->string('error_title')->nullable();
            $table->string('error_message')->nullable();
            $table->text('error_details')->nullable();
            $table->boolean('retryable')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('color', 24)->default('#16a34a');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('tag_thread', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->primary(['tag_id', 'thread_id']);
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_business_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('phone_number_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32)->default('meta_whatsapp');
            $table->string('event_type', 64)->nullable();
            $table->string('dedupe_hash', 64)->unique();
            $table->string('status', 32)->default('pending');
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('webhook_receipts');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('tag_thread');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('message_failures');
        Schema::dropIfExists('message_status_events');
        Schema::dropIfExists('message_parts');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('thread_assignments');
        Schema::dropIfExists('threads');
        Schema::dropIfExists('contact_channels');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('connection_sync_jobs');
        Schema::dropIfExists('connection_health_snapshots');
        Schema::dropIfExists('connection_tokens');
        Schema::dropIfExists('connection_onboarding_sessions');
        Schema::dropIfExists('phone_numbers');
        Schema::dropIfExists('whatsapp_business_accounts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_workspace_id');
        });

        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('workspaces');
    }
};
