<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->boolean('can_manage_flows')->default(false);
        });

        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('graph');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flows');

        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->dropColumn('can_manage_flows');
        });
    }
};
