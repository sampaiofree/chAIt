<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace ?? $user->availableWorkspaces()->first();
        abort_if(! $workspace instanceof Workspace, 403, 'Nenhum workspace disponível para este usuário.');

        if ($user->current_workspace_id !== $workspace->id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        }

        return Inertia::render('dashboard', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug, 'timezone' => $workspace->timezone],
            'currentUser' => ['id' => $user->id, 'name' => $user->name],
            'isAdmin' => $user->canAccessWorkspace($workspace, WorkspaceRole::Admin),
            'canViewUnassigned' => $user->canAccessWorkspace($workspace, WorkspaceRole::Admin)
                || (bool) $user->membershipFor($workspace)?->can_view_unassigned,
            'tags' => $workspace->tags()->orderBy('name')->get(['id', 'name', 'color']),
            'assignableMembers' => $user->canAccessWorkspace($workspace, WorkspaceRole::Admin)
                ? $workspace->memberships()->whereNotNull('joined_at')->with('user:id,name')->get()
                    ->map(fn ($membership) => ['id' => $membership->user_id, 'name' => $membership->user->name])->values()
                : [],
        ]);
    }
}
