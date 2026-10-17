<?php

namespace App\Jobs\Chat;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Workspace\UazapiChatController;
use App\Models\ChatOperation;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Chat\ChatPublisher;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiDeferredException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class ProcessConnectionOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $timeout = 90;

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
        return [new WorkspaceCapacity('automations')];
    }

    public function handle(UazapiClient $client, ChatPublisher $publisher): void
    {
        $operation = ChatOperation::find($this->operationId);
        if (! $operation || $operation->status !== 'pending' || $operation->expires_at->isPast()) {
            return;
        }
        $lock = Cache::lock('chat-connect:'.$operation->uazapi_connection_id, 110);
        if (! $lock->get()) {
            $this->release(1);

            return;
        }
        try {
            $connection = UazapiConnection::findOrFail($operation->uazapi_connection_id);
            $user = User::findOrFail($operation->user_id);
            abort_unless($user->canAccessWorkspace($connection->workspace, WorkspaceRole::Admin), 403);
            $operation->update(['status' => 'running']);
            if ($operation->kind === 'diagnose') {
                $request = Request::create('/', 'POST');
                $request->setUserResolver(fn () => $user);
                $request->attributes->set('chat_operation_direct', true);
                $response = app(UazapiChatController::class)->diagnose($request, $connection->workspace, $client);
                $result = $response->getData(true);
            } else {
                $payload = $operation->payload ?? ['step' => 'create'];
                if (! $connection->instance_token) {
                    $response = $client->createInstance($connection->instance_name);
                    $instance = $response['instance'] ?? $response;
                    $token = $response['token'] ?? data_get($instance, 'token') ?? data_get($instance, 'instanceToken');
                    if (! is_string($token) || $token === '') {
                        throw new \RuntimeException('instance_result_unknown');
                    }
                    $secret = hash_hmac('sha256', 'uazapi-webhook:'.$connection->id, (string) config('app.key'));
                    $connection->update(['instance_token' => $token, 'instance_id' => (string) ($instance['id'] ?? $connection->instance_name), 'webhook_secret_hash' => hash('sha256', $secret), 'status' => 'disconnected']);
                }
                if (($payload['step'] ?? 'create') !== 'connect') {
                    $secret = hash_hmac('sha256', 'uazapi-webhook:'.$connection->id, (string) config('app.key'));
                    $client->configureWebhook($connection, route('webhooks.uazapi', ['connection' => $connection->id, 'secret' => $secret]));
                    $payload['step'] = 'connect';
                    $operation->update(['payload' => $payload]);
                }
                $qr = $client->connect($connection);
                $connection->update(['status' => 'connecting']);
                $result = ['data' => ['status' => 'connecting', 'qr_code' => $qr['qrcode'] ?? $qr['qrCode'] ?? $qr['base64'] ?? data_get($qr, 'instance.qrcode'), 'pairing_code' => $qr['pairingCode'] ?? data_get($qr, 'instance.paircode')]];
            }
            $operation->update(['status' => 'completed', 'payload' => null]);
            $publisher->send($operation->workspace_id, 'operation.finished', ['operation_id' => $operation->id, 'status' => 'completed', 'result' => $result], onlyUser: $operation->user_id, minimumRole: WorkspaceRole::Admin);
        } catch (UazapiDeferredException $error) {
            if ($error->reason === 'read_error') {
                $operation->increment('read_attempts');
            }
            if ($operation->read_attempts >= 3) {
                $operation->update(['status' => 'failed']);
                $publisher->send($operation->workspace_id, 'operation.finished', ['operation_id' => $operation->id, 'status' => 'failed', 'result' => ['message' => 'O provedor não concluiu a consulta.']], onlyUser: $operation->user_id, minimumRole: WorkspaceRole::Admin);
            } else {
                $operation->update(['status' => 'pending']);
                $this->release($error->reason === 'read_error' ? (2 ** $operation->read_attempts) + random_int(0, 1) : $error->delay);
            }
        } catch (\Throwable) {
            $operation->update(['status' => 'confirmation_pending']);
            $publisher->send($operation->workspace_id, 'operation.finished', ['operation_id' => $operation->id, 'status' => 'confirmation_pending', 'result' => ['message' => 'Resultado da conexão ainda não confirmado. Use Verificar conexão antes de tentar novamente.', 'data' => ['status' => 'confirmation_pending']]], onlyUser: $operation->user_id, minimumRole: WorkspaceRole::Admin);
        } finally {
            $lock->release();
        }
    }
}
