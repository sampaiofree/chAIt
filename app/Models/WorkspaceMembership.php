<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceMembershipFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class WorkspaceMembership extends Pivot
{
    /** @use HasFactory<WorkspaceMembershipFactory> */
    use HasFactory;

    protected $table = 'workspace_memberships';

    public $incrementing = true;

    protected $guarded = [];

    protected $attributes = [
        'can_view_own' => true,
        'can_view_all' => false,
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'invited_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'can_view_unassigned' => 'boolean',
            'can_view_own' => 'boolean',
            'can_view_all' => 'boolean',
            'can_manage_flows' => 'boolean',
            'can_use_flow_assistant' => 'boolean',
        ];
    }

    public function canSeeThread(Thread $thread): bool
    {
        return $thread->workspace_id === $this->workspace_id && ($this->seesAllThreads()
            || ($this->can_view_own && $thread->assigned_to_user_id === $this->user_id)
            || ($this->can_view_unassigned && $thread->assigned_to_user_id === null));
    }

    public function seesAllThreads(): bool
    {
        return WorkspaceRole::from($this->role)->atLeast(WorkspaceRole::Admin) || $this->can_view_all;
    }

    public function canSeeOwnThreads(): bool
    {
        return $this->seesAllThreads() || $this->can_view_own;
    }

    public function canAccessChat(): bool
    {
        return $this->seesAllThreads() || $this->can_view_own || $this->can_view_unassigned;
    }

    public function chatPermissionsSignature(): string
    {
        return hash('sha256', json_encode([$this->workspace_id, $this->user_id, $this->role,
            $this->can_view_own, $this->can_view_unassigned, $this->can_view_all]));
    }

    public function scopeVisibleThreads($query)
    {
        $query->where($query->getModel()->qualifyColumn('workspace_id'), $this->workspace_id);
        if ($this->seesAllThreads()) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->whereRaw('1 = 0');
            if ($this->can_view_own) {
                $q->orWhere('assigned_to_user_id', $this->user_id);
            }
            if ($this->can_view_unassigned) {
                $q->orWhereNull('assigned_to_user_id');
            }
        });
    }

    protected function roleEnum(): Attribute
    {
        return Attribute::get(fn () => WorkspaceRole::from($this->role));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
