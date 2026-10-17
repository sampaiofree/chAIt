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

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'invited_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'can_view_unassigned' => 'boolean',
            'can_manage_flows' => 'boolean',
            'can_use_flow_assistant' => 'boolean',
        ];
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
