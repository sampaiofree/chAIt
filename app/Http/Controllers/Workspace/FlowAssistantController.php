<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\Workspace;
use App\Services\FlowAssistant\AssistantAccess;
use App\Services\FlowAssistant\DraftSessionService;
use App\Services\FlowAssistant\FlowAssistantService;
use App\Services\Flows\FlowEditLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowAssistantController extends Controller
{
    public function __construct(private DraftSessionService $drafts, private FlowAssistantService $assistant, private AssistantAccess $access) {}

    private function flow(Request $request, Workspace $workspace, int $id, bool $useAi = false): Flow
    {
        $flow = $workspace->flows()->findOrFail($id);
        $this->access->check($request->user(), $flow, $useAi);
        abort_if($useAi && $flow->is_active, 409, 'Inative o fluxo antes de usar a IA.');
        if ($useAi) {
            app(FlowEditLock::class)->assertHeld($flow, $request->header('X-Flow-Edit-Token'), $request->user()->id);
        }

        return $flow;
    }

    private function revisions(Request $request): array
    {
        return $request->validate(['base_revision' => 'required|integer|min:1', 'generation' => 'nullable|uuid', 'draft_revision' => 'nullable|integer|min:1']);
    }

    public function state(Request $request, Workspace $workspace, int $flow): JsonResponse
    {
        $data = $request->validate(['before' => 'nullable|integer|min:1']);
        $model = $this->flow($request, $workspace, $flow);
        $state = $this->assistant->state($model, $request->user(), $data['before'] ?? null);
        if (! app(FlowEditLock::class)->held($model, $request->header('X-Flow-Edit-Token'), $request->user()->id)) {
            $state['conversation'] = null;
            $state['messages'] = [];
            $state['run'] = null;
            $state['can_use_ai'] = false;
            $state['configured'] = false;
        }

        return response()->json(['data' => $state]);
    }

    public function models(Request $request, Workspace $workspace, int $flow): JsonResponse
    {
        return response()->json(['data' => $this->assistant->models($this->flow($request, $workspace, $flow, true), $request->user())]);
    }

    public function updateSession(Request $request, Workspace $workspace, int $flow): JsonResponse
    {
        $model = $this->flow($request, $workspace, $flow);
        $data = $this->revisions($request) + $request->validate(['name' => 'required|string|max:120', 'graph' => 'required|array']);
        $this->drafts->update($model, $data);

        return response()->json(['data' => $this->assistant->state($model, $request->user())]);
    }

    public function message(Request $request, Workspace $workspace, int $flow): JsonResponse
    {
        $model = $this->flow($request, $workspace, $flow, true);
        $data = $this->revisions($request) + $request->validate(['content' => 'required|string|max:'.config('flow-assistant.message_limit'), 'model' => 'nullable|string|max:64']);
        $this->assistant->start($model, $request->user(), $data);

        return response()->json(['data' => $this->assistant->state($model, $request->user())], 202);
    }

    public function approve(Request $request, Workspace $workspace, int $flow, int $plan): JsonResponse
    {
        $model = $this->flow($request, $workspace, $flow, true);
        $this->assistant->start($model, $request->user(), $this->revisions($request) + $request->validate(['model' => 'nullable|string|max:64']), $plan);

        return response()->json(['data' => $this->assistant->state($model, $request->user())], 202);
    }

    public function stop(Request $request, Workspace $workspace, int $flow, int $run): JsonResponse
    {
        $model = $this->flow($request, $workspace, $flow);
        $this->assistant->stop($model, $run);

        return response()->json(['data' => $this->assistant->state($model, $request->user())]);
    }

    public function resume(Request $request, Workspace $workspace, int $flow, int $run): JsonResponse
    {
        $model = $this->flow($request, $workspace, $flow, true);
        $this->assistant->resume($model, $request->user(), $run, $this->revisions($request) + $request->validate(['model' => 'nullable|string|max:64']));

        return response()->json(['data' => $this->assistant->state($model, $request->user())], 202);
    }
}
