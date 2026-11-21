<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace ?? $user->availableWorkspaces()->first();
        abort_if(! $workspace instanceof Workspace, 403, 'Nenhum workspace disponível para este usuário.');

        if ($user->current_workspace_id !== $workspace->id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        }

        $membership = $user->membershipFor($workspace);
        $unrestricted = $membership->seesAllThreads();
        if (! $unrestricted && ! $membership->can_view_own && ! $membership->can_view_unassigned) {
            if ($user->hasLeadPermission($workspace, 'can_view_leads')) {
                return redirect()->route('workspaces.leads.index', $workspace);
            }
            if ($user->canManageFlows($workspace)) {
                return redirect()->route('workspaces.flows.index', $workspace);
            }

            return redirect('/settings/profile');
        }
        $connectionId = UazapiConnection::where('workspace_id', $workspace->id)->value('id');
        $allowedQuery = Thread::where('workspace_id', $workspace->id)->where('uazapi_connection_id', $connectionId);
        $membership->scopeVisibleThreads($allowedQuery);
        $allowed = $unrestricted ? [] : $allowedQuery->orderBy('id')->pluck('id')->all();
        $cacheContext = hash_hmac('sha256', json_encode([$request->session()->getId(), $user->id, $workspace->id, $connectionId, $membership?->role, $membership?->can_view_own, $membership?->can_view_unassigned, $unrestricted, $allowed]), config('app.key'));

        return Inertia::render('dashboard', [
            'chatCacheContext' => $cacheContext,
            'chatPermissionsSignature' => $membership->chatPermissionsSignature(),
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug, 'timezone' => $workspace->timezone],
            'currentUser' => ['id' => $user->id, 'name' => $user->name],
            'isAdmin' => $user->canAccessWorkspace($workspace, WorkspaceRole::Admin),
            'canActOnChat' => $membership->canSeeOwnThreads(),
            'canViewUnassigned' => $membership->seesAllThreads() || (bool) $membership->can_view_unassigned,
            'tags' => $workspace->tags()->orderBy('name')->get(['id', 'name', 'color']),
            'assignableMembers' => $user->canAccessWorkspace($workspace, WorkspaceRole::Admin)
                ? $workspace->memberships()->whereNotNull('joined_at')->with('user:id,name,access_blocked_at')->get()->filter(fn ($member) => $member->canSeeOwnThreads() && ! $member->user->access_blocked_at)
                    ->map(fn ($membership) => ['id' => $membership->user_id, 'name' => $membership->user->name])->values()
                : [],
        ]);
    }
}
