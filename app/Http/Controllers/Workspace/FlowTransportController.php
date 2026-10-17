<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\Workspace;
use App\Services\FlowAssistant\AssistantAccess;
use App\Services\FlowAssistant\FlowAssistantService;
use App\Services\Flows\FlowTransport;
use App\Services\Flows\FlowTransportContract;
use App\Services\Flows\FlowTransportGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FlowTransportController extends Controller
{
    private function flow(Request $request, Workspace $workspace, int $id): Flow
    {
        $flow = $workspace->flows()->findOrFail($id);
        app(AssistantAccess::class)->check($request->user(), $flow);

        return $flow;
    }

    private function input(Request $request): array
    {
        // TrimStrings must not remove bytes from the document before its size is checked.
        if ($request->isJson()) {
            $original = json_decode($request->getContent(), true);
            if (is_array($original) && array_key_exists('json', $original)) {
                $request->merge(['json' => $original['json']]);
            }
        }

        return $request->validate([
            'json' => ['required', 'string', 'max:'.FlowTransportContract::MAX_BYTES],
            'base_revision' => ['required', 'integer', 'min:1'], 'generation' => ['nullable', 'uuid'], 'draft_revision' => ['nullable', 'integer', 'min:1'],
        ], FlowTransportContract::messages());
    }

    public function validateImport(Request $request, Workspace $workspace, int $flow, FlowTransport $transport): JsonResponse
    {
        $model = $this->flow($request, $workspace, $flow);
        $data = $this->input($request);
        $transport->validate($model, $request->user(), $data, $data['json']);

        return response()->json(['data' => ['valid' => true]]);
    }

    public function import(Request $request, Workspace $workspace, int $flow, FlowTransport $transport): JsonResponse
    {
        $model = $this->flow($request, $workspace, $flow);
        $data = $this->input($request);
        $updated = $transport->import($model, $request->user(), $data, $data['json']);

        return response()->json(['data' => [
            'state' => app(FlowAssistantService::class)->state($updated, $request->user()),
            'tags' => $workspace->tags()->orderBy('name')->get(['id', 'name']),
            'fields' => $workspace->contactCustomFields()->orderBy('name')->get(['id', 'name', 'type']),
        ]]);
    }

    public function export(Request $request, Workspace $workspace, int $flow, FlowTransport $transport): Response
    {
        $model = $this->flow($request, $workspace, $flow);
        $data = $request->validate(['source' => ['required', 'in:draft,published']]);
        $json = json_encode($transport->export($model, $data['source']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response($json."\n", 200, ['Content-Type' => 'application/json; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.(Str::slug($model->name) ?: 'fluxo').'-'.$data['source'].'.json"', 'Cache-Control' => 'private, no-store']);
    }

    public function guide(Request $request, Workspace $workspace, int $flow, FlowTransportGuide $guide): Response
    {
        $this->flow($request, $workspace, $flow);

        return response($guide->markdown(), 200, ['Content-Type' => 'text/markdown; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="chait-guia-fluxos.md"', 'Cache-Control' => 'private, no-store']);
    }
}
