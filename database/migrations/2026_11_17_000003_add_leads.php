<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', fn (Blueprint $t) => $t->text('observations')->nullable());
        Schema::table('workspace_memberships', function (Blueprint $t) {
            foreach (['can_view_leads', 'can_edit_leads', 'can_create_tags', 'can_create_contact_fields'] as $permission) {
                $t->boolean($permission)->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('observations'));
        Schema::table('workspace_memberships', fn (Blueprint $t) => $t->dropColumn(['can_view_leads', 'can_edit_leads', 'can_create_tags', 'can_create_contact_fields']));
    }
};
