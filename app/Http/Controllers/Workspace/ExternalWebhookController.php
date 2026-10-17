<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\ExternalWebhook;
use App\Models\ExternalWebhookReceipt;
use App\Models\Workspace;
use App\Services\AuditLogService;
use App\Services\ExternalWebhooks\PayloadFields;
use App\Services\ExternalWebhooks\WebhookDefinition;
use App\Services\ExternalWebhooks\WebhookFailure;
use App\Services\ExternalWebhooks\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ExternalWebhookController extends Controller
{
    use EnsuresWorkspaceAccess;

    private function manage(Request $request, Workspace $workspace, ?int $id = null): ?ExternalWebhook
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);

        return $id === null ? null : ExternalWebhook::where('workspace_id', $workspace->id)->findOrFail($id);
    }

    private function summary(ExternalWebhook $webhook): array
    {
        return [...$webhook->only(['id', 'name', 'status']), 'url' => route('webhooks.external.receive', $webhook->endpoint_key)];
    }

    private function snapshot(ExternalWebhook $webhook): array
    {
        return [
            ...$this->summary($webhook), ...$webhook->only(['mappings', 'tag_rules', 'payload_fields', 'sample_received_at', 'capture_until']),
            'sample_payload' => $webhook->sample_payload,
            'receipts' => $webhook->receipts()->latest('id')->limit(20)->get(['id', 'status', 'error_code', 'warnings', 'duration_ms', 'created_at']),
        ];
    }

    public function settings(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 403);

        return $this->index($request, $workspace);
    }

    public function index(Request $request, Workspace $workspace): Response
    {
        $this->manage($request, $workspace);

        return Inertia::render('webhooks/index', ['workspace' => $workspace->only(['id', 'name']),
            'webhooks' => ExternalWebhook::where('workspace_id', $workspace->id)->orderBy('name')->get()->map(fn ($item) => $this->summary($item))]);
    }

    public function store(Request $request, Workspace $workspace, AuditLogService $audit): JsonResponse
    {
        $this->manage($request, $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Informe o nome do Webhook.');
        $webhook = ExternalWebhook::create(['workspace_id' => $workspace->id, 'name' => $name,
            'endpoint_key' => bin2hex(random_bytes(32)), 'status' => 'test', 'mappings' => [], 'tag_rules' => [], 'payload_fields' => []]);
        $audit->record($workspace, 'external_webhook.created', $request->user(), $webhook);

        return response()->json(['data' => $this->summary($webhook)], 201);
    }

    public function edit(Request $request, Workspace $workspace, int $webhook): Response
    {
        $model = $this->manage($request, $workspace, $webhook);

        return Inertia::render('webhooks/editor', ['workspace' => $workspace->only(['id', 'name']), 'webhook' => $this->snapshot($model),
            'fields' => $workspace->contactCustomFields()->where('type', 'text')->orderBy('name')->get(['id', 'name', 'type']),
            'tags' => $workspace->tags()->orderBy('name')->get(['id', 'name', 'color'])]);
    }

    public function update(Request $request, Workspace $workspace, int $webhook, WebhookDefinition $definition, AuditLogService $audit): JsonResponse
    {
        $model = $this->manage($request, $workspace, $webhook);
        DB::transaction(function () use ($request, $workspace, $model, $definition, $audit): void {
            Workspace::whereKey($model->workspace_id)->lockForUpdate()->firstOrFail();
            $current = ExternalWebhook::whereKey($model->id)->lockForUpdate()->firstOrFail();
            $data = $definition->validate($workspace, $request->all(), $current->payload_fields);
            $current->update($data);
            $audit->record($workspace, 'external_webhook.updated', $request->user(), $current);
        });

        return response()->json(['data' => $this->snapshot($model->fresh())]);
    }

    public function poll(Request $request, Workspace $workspace, int $webhook): JsonResponse
    {
        return response()->json(['data' => $this->snapshot($this->manage($request, $workspace, $webhook))])->header('Cache-Control', 'private, no-store');
    }

    public function sample(Request $request, Workspace $workspace, int $webhook, PayloadFields $fields): JsonResponse
    {
        $model = $this->manage($request, $workspace, $webhook);
        $data = $request->validate(['payload' => ['required', 'string']]);
        try {
            $payload = $fields->parse($data['payload']);
            $this->captureSample($model, $data['payload'], $fields->extract($payload));
        } catch (WebhookFailure $error) {
            return $this->failure($error);
        }

        return response()->json(['data' => $this->snapshot($model->fresh())]);
    }

    public function clearSample(Request $request, Workspace $workspace, int $webhook): JsonResponse
    {
        $model = $this->manage($request, $workspace, $webhook);
        $model->update(['sample_payload' => null, 'sample_received_at' => null, 'capture_until' => null]);

        return response()->json(['data' => $this->snapshot($model->fresh())]);
    }

    public function capture(Request $request, Workspace $workspace, int $webhook): JsonResponse
    {
        $model = $this->manage($request, $workspace, $webhook);
        $model->update(['capture_until' => now()->addMinutes(10)]);

        return response()->json(['data' => $this->snapshot($model->fresh())]);
    }

    public function simulate(Request $request, Workspace $workspace, int $webhook, PayloadFields $fields, WebhookDefinition $definition, WebhookProcessor $processor): JsonResponse
    {
        $model = $this->manage($request, $workspace, $webhook);
        $data = $request->validate(['payload' => ['required', 'string']]);
        $receipt = $this->receipt($model);
        $start = microtime(true);
        try {
            $payload = $fields->parse($data['payload']);
            $configuration = $definition->validate($workspace, [...$request->all(), 'status' => 'active'], $fields->extract($payload));
            $prepared = $processor->prepare($model, $payload, $configuration, true);
            $receipt->update(['status' => 'simulated', 'warnings' => $prepared['warnings'], 'duration_ms' => $this->duration($start)]);

            // Preview contains proposed field names and tag IDs, never contact values.
            return response()->json(['data' => ['status' => 'simulated', 'warnings' => $prepared['warnings'], 'native_fields' => array_keys($prepared['native']),
                'custom_fields' => array_keys($prepared['custom']), 'tag_actions' => $prepared['tags'], 'matched_rules' => $prepared['matched']]]);
        } catch (WebhookFailure $error) {
            $receipt->update(['status' => 'error', 'error_code' => $error->errorCode, 'duration_ms' => $this->duration($start)]);

            return $this->failure($error);
        } catch (ValidationException $error) {
            $receipt->update(['status' => 'error', 'error_code' => 'invalid_configuration', 'duration_ms' => $this->duration($start)]);
            throw $error;
        }
    }

    public function receive(Request $request, string $endpointKey, PayloadFields $fields, WebhookProcessor $processor): JsonResponse
    {
        try {
            return $this->receiveEvent($request, $endpointKey, $fields, $processor);
        } catch (Throwable $error) {
            // Covers lookup/cache/receipt outages too; never log the key or SQL bindings.
            Log::error('External webhook reception failed.', ['exception_type' => $error::class]);

            return $this->failure(new WebhookFailure('internal_error', 'Não foi possível processar a requisição. Tente novamente.', 500));
        }
    }

    private function receiveEvent(Request $request, string $endpointKey, PayloadFields $fields, WebhookProcessor $processor): JsonResponse
    {
        $model = ExternalWebhook::where('endpoint_key', $endpointKey)->first();
        if (! $model) {
            return $this->failure(new WebhookFailure('not_found', 'Webhook não disponível.', 404));
        }
        $key = 'external-webhook:'.$model->id;
        if (RateLimiter::tooManyAttempts($key, config('external-webhooks.requests_per_minute'))) {
            return $this->failure(new WebhookFailure('rate_limited', 'Limite de requisições excedido.', 429))->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);
        $receipt = $this->receipt($model);
        $start = microtime(true);
        try {
            if (! $request->isJson()) {
                throw new WebhookFailure('unsupported_media_type', 'Envie Content-Type: application/json.', 415);
            }
            $raw = $request->getContent();
            $payload = $fields->parse($raw);
            // Capture survives CRM rejection, so administrators can inspect the real event.
            DB::transaction(function () use ($model, $raw, $payload, $fields): void {
                Workspace::whereKey($model->workspace_id)->lockForUpdate()->firstOrFail();
                $current = ExternalWebhook::whereKey($model->id)->lockForUpdate()->firstOrFail();
                if ($current->status === 'test' || $current->capture_until?->isFuture()) {
                    $this->captureSample($current, $raw, $fields->extract($payload));
                }
            }, 3);
            $result = DB::transaction(function () use ($model, $receipt, $payload, $processor, $start): array {
                Workspace::whereKey($model->workspace_id)->lockForUpdate()->firstOrFail();
                $current = ExternalWebhook::whereKey($model->id)->lockForUpdate()->firstOrFail();
                $result = $current->status === 'test' ? ['status' => 'received', 'warnings' => []]
                    : $processor->apply($current, $processor->prepare($current, $payload));
                $receipt->update([...$result, 'duration_ms' => $this->duration($start)]);

                return $result;
            }, 3);

            return response()->json(['status' => $result['status'], 'receipt_id' => $receipt->id, 'warnings' => $result['warnings']]);
        } catch (WebhookFailure $error) {
            $receipt->update(['status' => 'error', 'error_code' => $error->errorCode, 'duration_ms' => $this->duration($start)]);

            return $this->failure($error);
        } catch (Throwable $error) {
            // Never report the original exception: SQL bindings may include CRM values.
            Log::error('External webhook processing failed.', ['webhook_id' => $model->id, 'receipt_id' => $receipt->id, 'exception_type' => $error::class]);
            $receipt->update(['status' => 'error', 'error_code' => 'internal_error', 'duration_ms' => $this->duration($start)]);

            return $this->failure(new WebhookFailure('internal_error', 'Não foi possível processar a requisição. Tente novamente.', 500));
        }
    }

    private function captureSample(ExternalWebhook $model, string $raw, array $fields): void
    {
        $model->update(['sample_payload' => $raw, 'payload_fields' => $fields, 'sample_received_at' => now(), 'capture_until' => null]);
    }

    private function receipt(ExternalWebhook $model): ExternalWebhookReceipt
    {
        return ExternalWebhookReceipt::create(['workspace_id' => $model->workspace_id, 'external_webhook_id' => $model->id, 'status' => 'received']);
    }

    private function duration(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function failure(WebhookFailure $error): JsonResponse
    {
        return response()->json(['status' => 'error', 'code' => $error->errorCode, 'message' => $error->getMessage()], $error->httpStatus);
    }
}
