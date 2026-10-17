<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\Flows\AiSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class OpenAiController extends Controller
{
    use EnsuresWorkspaceAccess;

    public function edit(Request $request): Response
    {
        $workspace = $this->workspace($request);

        return Inertia::render('settings/openai', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'configured' => WorkspaceOpenAiIntegration::where('workspace_id', $workspace->id)->exists(),
        ]);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        $data = $request->validate(['api_key' => ['required', 'string', 'starts_with:sk-', 'max:512']]);
        $key = trim($data['api_key']);
        try {
            $models = Http::withToken($key)->acceptJson()->timeout(10);
            $probe = $models->get('https://api.openai.com/v1/models');
        } catch (\Throwable) {
            return response()->json(['message' => 'Não foi possível verificar a chave OpenAI agora.'], 503);
        }
        if (! $probe->successful()) {
            return response()->json(['message' => 'A chave OpenAI foi recusada. Confira o acesso da conta.'], 422);
        }
        $existing = WorkspaceOpenAiIntegration::where('workspace_id', $workspace->id)->first();
        if ($existing) {
            app(AiSessionService::class)->prepareCredentialChange($workspace->id, $existing->api_key);
        }
        WorkspaceOpenAiIntegration::updateOrCreate(['workspace_id' => $workspace->id], ['api_key' => $key]);

        return response()->json(['data' => ['configured' => true]]);
    }

    public function destroy(Request $request, Workspace $workspace, AiSessionService $sessions): JsonResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        $integration = WorkspaceOpenAiIntegration::where('workspace_id', $workspace->id)->first();
        if (! $integration) {
            return response()->json(['data' => ['configured' => false]]);
        }
        $sessions->prepareCredentialChange($workspace->id, $integration->api_key);
        $integration->delete();

        return response()->json(['data' => ['configured' => false]]);
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 403);
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);

        return $workspace;
    }
}
