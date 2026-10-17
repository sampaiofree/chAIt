<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('workspaces') || ! Schema::hasTable('workspace_memberships')) {
            return;
        }

        $users = DB::table('users')
            ->whereNull('current_workspace_id')
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $existingMembership = DB::table('workspace_memberships')
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->first();

            if ($existingMembership !== null) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['current_workspace_id' => $existingMembership->workspace_id]);

                continue;
            }

            $workspaceId = DB::table('workspaces')->insertGetId([
                'name' => "{$user->name}'s Workspace",
                'slug' => $this->uniqueWorkspaceSlug($user),
                'timezone' => config('app.timezone', 'UTC'),
                'settings' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('workspace_memberships')->insert([
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
                'role' => 'owner',
                'joined_at' => now(),
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('users')
                ->where('id', $user->id)
                ->update(['current_workspace_id' => $workspaceId]);
        }
    }

    public function down(): void
    {
        // Existing users were backfilled with a workspace. Reversing this safely is not deterministic.
    }

    private function uniqueWorkspaceSlug(object $user): string
    {
        $slugBase = Str::slug($user->name ?: Str::before((string) $user->email, '@'));
        $slugBase = $slugBase !== '' ? $slugBase : 'workspace';
        $slug = $slugBase;
        $suffix = 1;

        while (DB::table('workspaces')->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$slugBase}-{$suffix}";
        }

        return $slug;
    }
};
