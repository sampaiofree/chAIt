<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentWorkspaceController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless(
            $request->user()->workspaces()->whereKey($workspace->id)->exists(),
            403,
            'Você não faz parte deste workspace.',
        );

        $request->user()->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();

        return redirect()->route('dashboard');
    }
}
