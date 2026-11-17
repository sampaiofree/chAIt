<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\Chat\ChatConnectionAccess;
use Closure;
use Illuminate\Http\Request;

class RequireConnectedChat
{
    public function handle(Request $request, Closure $next)
    {
        if (str_contains($request->path(), '/conversations')) {
            $workspace = $request->route('workspace');
            if (! $workspace instanceof Workspace) {
                $workspace = Workspace::findOrFail($workspace);
            }
            abort_unless($request->user()?->canAccessWorkspace($workspace), 403);
            $state = app(ChatConnectionAccess::class)->state($workspace);
            if (! $state['allowed']) {
                return response()->json(['code' => 'whatsapp_'.$state['status'], 'message' => 'Conecte o WhatsApp para usar o Chat.'], $state['status'] === 'disconnected' ? 409 : 503);
            }
        }

        return $next($request);
    }
}
