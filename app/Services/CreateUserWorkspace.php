<?php

namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateUserWorkspace
{
    public function create(User $user, string $name, bool $makeCurrent = false): Workspace
    {
        return DB::transaction(function () use ($user, $name, $makeCurrent): Workspace {
            $workspace = Workspace::query()->create([
                'name' => $name,
                'slug' => (Str::slug(Str::limit($name, 180, '')) ?: 'workspace').'-'.Str::lower((string) Str::ulid()),
                'timezone' => config('app.timezone', 'UTC'),
            ]);
            $workspace->memberships()->create([
                'user_id' => $user->id,
                'role' => WorkspaceRole::Owner->value,
                'joined_at' => now(),
                'last_seen_at' => now(),
            ]);
            if ($makeCurrent) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
            }

            return $workspace;
        });
    }
}
