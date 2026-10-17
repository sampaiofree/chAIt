<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppController extends Controller
{
    use EnsuresWorkspaceAccess;

    public function __invoke(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 403);
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);

        return Inertia::render('settings/whatsapp', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
        ]);
    }
}
