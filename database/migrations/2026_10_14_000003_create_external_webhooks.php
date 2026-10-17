<?php

use App\Services\Contacts\InternationalPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $normalizer = new InternationalPhone;
        $updates = [];
        $seen = [];
        // Check the final keys before changing any data; never merge CRM records.
        foreach (DB::table('contacts')->orderBy('id')->cursor() as $contact) {
            $phone = $normalizer->normalize($contact->phone) ?? $contact->phone;
            if ($phone !== null) {
                $key = $contact->workspace_id.':'.$phone;
                if (isset($seen[$key])) {
                    throw new RuntimeException('Contact phone collision: workspace '.$contact->workspace_id.', contact IDs '.$seen[$key].' and '.$contact->id.'. Resolve before migrating.');
                }
                $seen[$key] = $contact->id;
            }
            if ($phone !== $contact->phone) {
                $updates[$contact->id] = $phone;
            }
        }
        foreach ($updates as $id => $phone) {
            DB::table('contacts')->where('id', $id)->update(['phone' => $phone]);
        }
        Schema::table('contacts', fn (Blueprint $table) => $table->unique(['workspace_id', 'phone'], 'contacts_workspace_phone_unique'));

        Schema::create('external_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('endpoint_key', 64)->unique();
            $table->string('status', 16)->default('test');
            $table->json('mappings');
            $table->json('tag_rules');
            $table->json('payload_fields');
            $table->text('sample_payload')->nullable();
            $table->timestamp('sample_received_at')->nullable();
            $table->timestamp('capture_until')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'id']);
        });
        Schema::create('external_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_webhook_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
            $table->index(['external_webhook_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_webhook_receipts');
        Schema::dropIfExists('external_webhooks');
        Schema::table('contacts', fn (Blueprint $table) => $table->dropUnique('contacts_workspace_phone_unique'));
    }
};
