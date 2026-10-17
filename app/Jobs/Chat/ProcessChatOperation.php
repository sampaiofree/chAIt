<?php

namespace App\Jobs\Chat;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Workspace\UazapiChatController;
use App\Models\AiSession;
use App\Models\ChatOperation;
use App\Models\FlowAsset;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\User;
use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Chat\ChatPublisher;
use App\Services\Chat\TransportContext;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Flows\FlowMedia;
use App\Services\Flows\FlowRuntime;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiDeferredException;
use App\Services\Uazapi\UazapiRejectedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProcessChatOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $timeout = 180;

    public function __construct(public string $operationId)
    {
        $this->onQueue('automations');
    }

    public function workspaceId(): ?int
    {
        return ChatOperation::whereKey($this->operationId)->value('workspace_id');
    }

    public function middleware(): array
    {
        return [new WorkspaceCapacity(in_array($this->queue, ['media', 'default'], true) ? $this->queue : 'automations')];
    }

    public function handle(UazapiClient $client, ChatPublisher $publisher): void
    {
        $lock = Cache::lock('chat-operation:'.$this->operationId, 200);
        if (! $lock->get()) {
            $this->release(1);

            return;
        }
        $operation = null;
        $uploadCopy = null;
        $context = app(TransportContext::class);
        $context->direct = true;
        try {
            $operation = ChatOperation::find($this->operationId);
            if (! $operation || $operation->status !== 'pending') {
                return;
            }
            if ($operation->expires_at->isPast()) {
                $operation->update(['status' => 'expired', 'payload' => null]);

                return;
            }
            $thread = Thread::findOrFail($operation->thread_id);
            $payload = $operation->payload ?? [];
            if ($operation->flow_execution_id) {
                $execution = FlowExecution::find($operation->flow_execution_id);
                if (! $execution || ! in_array($execution->status, ($payload['allow_completed'] ?? false) ? [...FlowExecutionManager::ACTIVE, 'completed'] : FlowExecutionManager::ACTIVE, true) || app(FlowRuntime::class)->isPaused($execution)) {
                    $this->settle($operation, 'cancelled', [], $publisher);

                    return;
                }
            }
            // A send which started before a crash is never replayed. Recovery marks it uncertain.
            $operation->update(['status' => 'running']);
            if ($operation->kind === 'transport') {
                $connection = $thread->uazapiConnection;
                abort_unless($connection && $connection->status === 'connected', 409);
                if (str_starts_with((string) $operation->track_id, 'ai-media-')) {
                    preg_match('/^ai-media-([0-9]+)-/', $operation->track_id, $matches);
                    if (! isset($matches[1]) || AiSession::whereKey($matches[1])->value('status') !== 'active') {
                        $this->settle($operation, 'cancelled', [], $publisher);

                        return;
                    }
                }
                $message = match ($payload['transport']) {
                    'text' => $client->sendText($connection, $payload['to'], $payload['text'], $operation->track_id),
                    'menu' => $client->sendMenu($connection, $payload['to'], $payload['format'], $payload['question'], $payload['choices'], $payload['list_button'], $operation->track_id),
                    'asset' => $this->asset($operation, $thread, $payload, $client),
                };
                $this->settle($operation, 'completed', ['message' => $message], $publisher);

                return;
            }
            $user = User::findOrFail($operation->user_id);
            abort_unless($user->canAccessWorkspace($thread->workspace), 403);
            $request = Request::create('/', in_array($operation->kind, ['send', 'send_file', 'read'], true) ? 'POST' : 'GET', $payload);
            $request->headers->set('Accept', 'application/json');
            $request->attributes->set('chat_operation_direct', true);
            $request->setUserResolver(fn () => $user);
            if (isset($payload['file_path'])) {
                abort_unless(Storage::disk('local')->exists($payload['file_path']), 410);
                $uploadCopy = tempnam(sys_get_temp_dir(), 'chait-upload-');
                copy(Storage::disk('local')->path($payload['file_path']), $uploadCopy);
                $request->files->set('file', new UploadedFile($uploadCopy, $payload['file_name'], $payload['file_mime'], null, true));
            }
            if ($operation->kind === 'media') {
                $member = $user->membershipFor($thread->workspace);
                abort_unless(WorkspaceRole::from($member->role)->atLeast(WorkspaceRole::Admin) || $member->can_view_unassigned || $thread->assigned_to_user_id === $user->id, 404);
                $messageId = (string) ($payload['message_id'] ?? '');
                abort_unless($messageId !== '' && strlen($messageId) <= 191, 404);
                if (! ($payload['media_verified'] ?? false)) {
                    $found = $client->findMessage($thread->uazapiConnection, $messageId);
                    $items = $found['messages'] ?? $found['data'] ?? $found['items'] ?? [];
                    abort_unless(collect($items)->contains(fn ($item) => (string) ($item['id'] ?? $item['messageid'] ?? '') === $messageId && (string) ($item['chatid'] ?? data_get($item, 'key.remoteJid') ?? '') === $thread->external_chat_id), 404);
                    $payload['media_verified'] = true;
                    $operation->update(['payload' => $payload]);
                }
                $download = $client->downloadMessage($thread->uazapiConnection, $messageId);
                $this->settle($operation, 'completed', ['data' => ['url' => $download['fileURL'] ?? null]], $publisher);

                return;
            }
            $controller = app(UazapiChatController::class);
            $method = match ($operation->kind) {
                'history' => 'history', 'avatar' => 'avatar', 'media' => 'media', 'read' => 'markRead', default => 'send'
            };
            $response = app()->call([$controller, $method], ['request' => $request, 'workspace' => $thread->workspace, 'thread' => $thread, 'messageId' => $payload['message_id'] ?? '']);
            $result = $response->getData(true);
            if ($response->getStatusCode() >= 500 && in_array($operation->kind, ['history', 'avatar', 'media'], true) && $operation->read_attempts < 2) {
                throw new UazapiDeferredException(1, 'read_error');
            }
            $status = $response->getStatusCode() === 202 ? 'confirmation_pending' : ($response->isSuccessful() ? 'completed' : 'failed');
            $this->settle($operation, $status, $result, $publisher);
        } catch (UazapiDeferredException $error) {
            if ($operation) {
                if ($error->reason === 'read_error' && $operation->read_attempts >= 2) {
                    $this->settle($operation, 'failed', ['message' => 'O provedor não concluiu a consulta.'], $publisher);

                    return;
                }
                $attempts = $operation->read_attempts + ($error->reason === 'read_error' ? 1 : 0);
                $operation->update(['status' => 'pending', 'read_attempts' => $attempts]);
                $this->release($error->reason === 'read_error' ? (2 ** $attempts) + random_int(0, 1) : $error->delay);
            }
        } catch (\Throwable $error) {
            if ($operation) {
                $mutating = in_array($operation->kind, ['send', 'send_file', 'transport'], true);
                $explicit = $error instanceof HttpExceptionInterface || $error instanceof ValidationException || $error instanceof UazapiRejectedException;
                $this->settle($operation, $mutating && ! $explicit ? 'confirmation_pending' : 'failed', ['message' => 'Não foi possível concluir a operação.'], $publisher);
            }
        } finally {
            if ($uploadCopy && is_file($uploadCopy)) {
                unlink($uploadCopy);
            } $context->direct = false;
            $lock->release();
        }
    }

    private function asset(ChatOperation $operation, Thread $thread, array $payload, UazapiClient $client): array
    {
        $asset = FlowAsset::where('workspace_id', $operation->workspace_id)->findOrFail($payload['asset_id']);
        $bytes = app(FlowMedia::class)->contents($asset);

        return $client->sendMedia($thread->uazapiConnection, $payload['to'], 'data:'.$asset->mime_type.';base64,'.base64_encode($bytes), $payload['media_type'], $payload['caption'] ?? null, $asset->original_name, $asset->mime_type, $operation->track_id);
    }

    private function settle(ChatOperation $operation, string $status, array $result, ChatPublisher $publisher): void
    {
        $payload = $operation->payload ?? [];
        $operation->update(['status' => $status, 'payload' => in_array($status, ['completed', 'cancelled'], true) ? null : $operation->payload, 'error_code' => $status === 'failed' ? 'operation_failed' : null]);
        if (in_array($status, ['completed', 'cancelled'], true) && isset($payload['file_path'])) {
            Storage::disk('local')->delete($payload['file_path']);
        }
        if ($operation->kind === 'history' && $operation->user_id && isset($result['data']) && strlen(json_encode($result)) > 100000) {
            $result['expected_messages'] = count($result['data']);
            foreach ($result['data'] as $item) {
                $publisher->send($operation->workspace_id, 'operation.chunk', ['operation_id' => $operation->id, 'items' => [$item]], $operation->thread_id, $operation->user_id);
            }
            $result['data'] = [];
        }
        if ($operation->user_id) {
            $publisher->send($operation->workspace_id, 'operation.finished', ['operation_id' => $operation->id, 'status' => $status, 'result' => $result], $operation->thread_id, $operation->user_id);
        }
    }
}
