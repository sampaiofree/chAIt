<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\WorkspaceRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'phone', 'password', 'current_workspace_id', 'appearance'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::created(function (self $user): void {
            if ($user->current_workspace_id !== null) {
                return;
            }

            $slugBase = Str::slug($user->name ?: Str::before($user->email, '@'));
            $slugBase = $slugBase !== '' ? $slugBase : 'workspace';
            $slug = $slugBase;
            $suffix = 1;

            while (Workspace::query()->where('slug', $slug)->exists()) {
                $suffix++;
                $slug = "{$slugBase}-{$suffix}";
            }

            $workspace = Workspace::query()->create([
                'name' => "{$user->name}'s Workspace",
                'slug' => $slug,
                'timezone' => config('app.timezone', 'UTC'),
            ]);

            WorkspaceMembership::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => WorkspaceRole::Owner->value,
                'joined_at' => now(),
                'last_seen_at' => now(),
            ]);

            $user->forceFill([
                'current_workspace_id' => $workspace->id,
            ])->saveQuietly();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships')
            ->using(WorkspaceMembership::class)
            ->withPivot(['role', 'invited_at', 'joined_at', 'last_seen_at', 'can_view_unassigned', 'can_manage_flows', 'can_use_flow_assistant'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function assignedThreads(): HasMany
    {
        return $this->hasMany(Thread::class, 'assigned_to_user_id');
    }

    /**
     * @return Collection<int, Workspace>
     */
    public function availableWorkspaces(): Collection
    {
        return $this->workspaces()->orderBy('name')->get();
    }

    public function membershipFor(Workspace $workspace): ?WorkspaceMembership
    {
        return $this->memberships->firstWhere('workspace_id', $workspace->id)
            ?? $this->memberships()->whereBelongsTo($workspace)->first();
    }

    public function canAccessWorkspace(Workspace $workspace, WorkspaceRole $minimumRole = WorkspaceRole::Agent): bool
    {
        $membership = $this->membershipFor($workspace);

        if ($membership === null) {
            return false;
        }

        return WorkspaceRole::from($membership->role)->atLeast($minimumRole);
    }

    public function canManageFlows(Workspace $workspace): bool
    {
        $membership = $this->membershipFor($workspace);

        return $membership !== null && (WorkspaceRole::from($membership->role)->atLeast(WorkspaceRole::Admin) || $membership->can_manage_flows);
    }

    public function canUseFlowAssistant(Workspace $workspace): bool
    {
        $membership = $this->membershipFor($workspace);

        return $this->canManageFlows($workspace) && $membership !== null
            && (WorkspaceRole::from($membership->role)->atLeast(WorkspaceRole::Admin) || $membership->can_use_flow_assistant);
    }
}
