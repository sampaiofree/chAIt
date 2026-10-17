<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;

trait EnsuresWorkspaceAccess
{
    /**
     * @throws AuthorizationException
     */
    protected function ensureWorkspaceAccess(User $user, Workspace $workspace, WorkspaceRole $minimumRole = WorkspaceRole::Agent): void
    {
        if (! $user->canAccessWorkspace($workspace, $minimumRole)) {
            throw new AuthorizationException('You do not have access to this workspace.');
        }
    }
}
