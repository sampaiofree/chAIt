<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected $guarded = [];

    public function getIconUrlAttribute(): ?string
    {
        return $this->icon_path ? Storage::disk('public')->url($this->icon_path) : null;
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_memberships')
            ->using(WorkspaceMembership::class)
            ->withPivot(['role', 'invited_at', 'joined_at', 'last_seen_at', 'can_view_unassigned'])
            ->withTimestamps();
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }

    public function flowFolders(): HasMany
    {
        return $this->hasMany(FlowFolder::class);
    }

    public function contactCustomFields(): HasMany
    {
        return $this->hasMany(ContactCustomField::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }
}
