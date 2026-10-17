<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    use EnsuresWorkspaceAccess;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        return Inertia::render('settings/workspace', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'icon_url' => $workspace->icon_url,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $oldPath = $workspace->icon_path;
        $newPath = $request->file('icon')?->store("workspaces/{$workspace->id}/icons", 'public');

        try {
            $workspace->update([
                'name' => trim($data['name']),
                'icon_path' => $newPath ?: $oldPath,
            ]);
        } catch (\Throwable $error) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }
            throw $error;
        }

        if ($newPath && $oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return back()->with('success', 'Workspace atualizado.');
    }

    public function destroyIcon(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $oldPath = $workspace->icon_path;
        $workspace->update(['icon_path' => null]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return back()->with('success', 'Ícone removido.');
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 403);
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);

        return $workspace;
    }
}
