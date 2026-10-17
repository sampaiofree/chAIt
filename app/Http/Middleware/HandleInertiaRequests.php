<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'chatRealtime' => ['enabled' => config('chat.enabled'), 'key' => config('broadcasting.connections.reverb.key'), 'host' => config('broadcasting.connections.reverb.options.host'), 'port' => config('broadcasting.connections.reverb.options.port'), 'scheme' => config('broadcasting.connections.reverb.options.scheme')],
            'appearance' => $request->user()?->appearance ?? 'system',
            'auth' => [
                'user' => $request->user()?->loadMissing('currentWorkspace'),
            ],
            'workspaces' => fn () => $request->user()
                ? $request->user()->availableWorkspaces()->map(fn (Workspace $workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'icon_url' => $workspace->icon_url,
                ])->values()
                : [],
            'currentWorkspace' => fn () => $request->user()?->currentWorkspace
                ? [
                    'id' => $request->user()->currentWorkspace->id,
                    'name' => $request->user()->currentWorkspace->name,
                    'slug' => $request->user()->currentWorkspace->slug,
                    'icon_url' => $request->user()->currentWorkspace->icon_url,
                    'timezone' => $request->user()->currentWorkspace->timezone,
                ]
                : null,
            'isAdmin' => fn () => $request->user()?->currentWorkspace
                ? $request->user()->canAccessWorkspace($request->user()->currentWorkspace, WorkspaceRole::Admin)
                : false,
            'canManageFlows' => fn () => $request->user()?->currentWorkspace
                ? $request->user()->canManageFlows($request->user()->currentWorkspace)
                : false,
            'sidebarOpen' => $request->hasCookie('sidebar_state') && $request->cookie('sidebar_state') === 'true',
        ];
    }
}
