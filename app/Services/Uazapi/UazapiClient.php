<?php

namespace App\Services\Uazapi;

use App\Models\UazapiConnection;
use App\Services\Chat\ChatOperations;
use App\Services\Chat\TransportContext;
use App\Services\Flows\AiTurnBudget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UazapiClient
{
    private function request(?UazapiConnection $connection = null, ?AiTurnBudget $budget = null): PendingRequest
    {
        $baseUrl = trim((string) config('services.uazapi.base_url'));
        if ($baseUrl === '') {
            throw new RuntimeException('UAZAPI_BASE_URL não está configurada.');
        }
        $token = $connection?->instance_token ?: config('services.uazapi.admin_token');

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()->asJson()->timeout($budget?->timeout(25) ?? 25)
            ->withHeaders([$connection ? 'token' : 'admintoken' => $token]);
    }

    private function execute(?UazapiConnection $connection, string $method, string $path, mixed $payload = null, ?AiTurnBudget $budget = null, bool $interactive = false, bool $read = false)
    {
        $bucket = $connection ? 'connection:'.$connection->id : 'admin:'.hash('sha256', (string) config('services.uazapi.base_url'));
        $started = hrtime(true);
        $release = config('chat.enabled') ? app(UazapiGate::class)->acquire($bucket, $interactive) : fn () => null;
        try {
            $request = $this->request($connection, $budget);
            $response = $method === 'get' ? $request->get($path, $payload ?? []) : $request->post($path, $payload);
            if (config('chat.enabled') && $response->status() === 429) {
                $retryAfter = trim((string) $response->header('Retry-After'));
                $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : (strtotime($retryAfter) ?: now()->timestamp) - now()->timestamp;
                $delay = max(1, $seconds);
                app(UazapiGate::class)->cooldown($bucket, $delay);
                throw new UazapiDeferredException($delay, 'provider_limit');
            }
            if (config('chat.enabled') && $read && $response->serverError()) {
                throw new UazapiDeferredException(1, 'read_error');
            }

            return $response;
        } catch (ConnectionException $error) {
            if ($read && config('chat.enabled')) {
                throw new UazapiDeferredException(1, 'read_error');
            }
            throw new RuntimeException('provider_transport_unknown');
        } finally {
            $release();
            if (config('chat.enabled')) {
                try {
                    Cache::put('chat-provider:'.$bucket.':latency_ms', (int) ((hrtime(true) - $started) / 1000000), 3600);
                } catch (\Throwable) { /* Optional telemetry must not change a transport result. */
                }
            }
        }
    }

    public function createInstance(string $instanceName): array
    {
        return $this->send($this->execute(null, 'post', '/instance/create', ['name' => $instanceName]));
    }

    public function connect(UazapiConnection $connection): array
    {
        return $this->send($this->execute($connection, 'post', '/instance/connect', new \stdClass, interactive: true));
    }

    public function configureWebhook(UazapiConnection $connection, string $webhookUrl): array
    {
        return $this->send($this->execute($connection, 'post', '/webhook', [
            'enabled' => true,
            'url' => $webhookUrl,
            'events' => ['messages', 'messages_update', 'connection', 'chats'],
            'excludeMessages' => [],
            'addUrlEvents' => false,
            'addUrlTypesMessages' => false,
        ]));
    }

    public function webhookConfiguration(UazapiConnection $connection): array
    {
        return $this->send($this->execute($connection, 'get', '/webhook', read: true));
    }

    public function status(UazapiConnection $connection): array
    {
        return $this->send($this->execute($connection, 'get', '/instance/status', interactive: true, read: true));
    }

    public function avatar(UazapiConnection $connection, string $chatId, bool $preview = true): array
    {
        return $this->send($this->execute($connection, 'post', '/chat/avatar', ['number' => $chatId, 'preview' => $preview], read: true));
    }

    public function downloadMessage(UazapiConnection $connection, string $messageId, ?AiTurnBudget $budget = null): array
    {
        return $this->send($this->execute($connection, 'post', '/message/download', [
            'id' => $messageId,
            'generate_mp3' => true,
            'return_base64' => false,
            'transcribe' => false,
        ], $budget, read: true));
    }

    public function findMessage(UazapiConnection $connection, string $messageId, ?AiTurnBudget $budget = null): array
    {
        return $this->send($this->execute($connection, 'post', '/message/find', ['id' => $messageId], $budget, read: true));
    }

    public function messages(UazapiConnection $connection, string $chatId, int $offset = 0, ?string $trackId = null, ?AiTurnBudget $budget = null, bool $restrictToChat = false): array
    {
        return $this->send($this->execute($connection, 'post', '/message/find', array_filter([
            'chatid' => ($trackId === null || $restrictToChat) ? $chatId : null, 'limit' => 100, 'offset' => $offset, 'track_id' => $trackId,
        ], fn ($value) => $value !== null && $value !== ''), $budget, interactive: true, read: true));
    }

    public function markChatRead(UazapiConnection $connection, string $chatId, bool $read = true): array
    {
        return $this->send($this->execute($connection, 'post', '/chat/read', [
            'number' => $chatId,
            'read' => $read,
        ], interactive: true));
    }

    public function sendText(UazapiConnection $connection, string $to, string $text, ?string $trackId = null, ?AiTurnBudget $budget = null): array
    {
        if (config('chat.enabled') && ! app(TransportContext::class)->direct) {
            return app(ChatOperations::class)->transport($connection, 'text', ['to' => $to, 'text' => $text, 'track_id' => $trackId]);
        }
        $response = $this->execute($connection, 'post', '/send/text', array_filter([
            'number' => $to, 'text' => $text, 'track_id' => $trackId,
        ], fn ($value) => $value !== null), $budget, interactive: true);
        if ($response->clientError() && $response->status() !== 409) {
            throw new UazapiRejectedException($response->status());
        }
        $data = $this->send($response);
        if (in_array(strtolower((string) (data_get($data, 'status') ?? data_get($data, 'message.status'))), ['failed', 'canceled', 'cancelled', 'error'], true)) {
            throw new UazapiRejectedException(422);
        }
        if (! filled(data_get($data, 'messageid')) && ! filled(data_get($data, 'id')) && ! filled(data_get($data, 'message.id')) && ! filled(data_get($data, 'message.messageid'))) {
            throw new RuntimeException('A UAZAPI ainda não confirmou o envio.');
        }

        return $data;
    }

    public function sendMenu(UazapiConnection $connection, string $to, string $format, string $question, array $choices, ?string $listButton, string $trackId): array
    {
        if (config('chat.enabled') && ! app(TransportContext::class)->direct) {
            return app(ChatOperations::class)->transport($connection, 'menu', ['to' => $to, 'format' => $format, 'question' => $question, 'choices' => $choices, 'list_button' => $listButton, 'track_id' => $trackId]);
        }
        $response = $this->execute($connection, 'post', '/send/menu', array_filter([
            'number' => $to, 'type' => $format, 'text' => $question, 'choices' => $choices,
            'listButton' => $format === 'list' ? $listButton : null, 'track_id' => $trackId,
        ], fn ($value) => $value !== null), interactive: true);
        if ($response->clientError() && $response->status() !== 409) {
            throw new UazapiRejectedException($response->status());
        }

        return $this->send($response);
    }

    public function sendMedia(UazapiConnection $connection, string $to, string $url, string $type, ?string $caption = null, ?string $filename = null, ?string $mimeType = null, ?string $trackId = null, ?AiTurnBudget $budget = null): array
    {
        $response = $this->execute($connection, 'post', '/send/media', array_filter([
            'number' => $to, 'type' => $type, 'file' => $url, 'text' => $caption,
            'docName' => $filename, 'mimetype' => $mimeType, 'track_id' => $trackId,
        ], fn ($value) => $value !== null), $budget, interactive: true);

        if ($response->clientError() && $response->status() !== 409) {
            throw new UazapiRejectedException($response->status());
        }

        $data = $this->send($response);
        $message = is_array(data_get($data, 'message')) ? $data['message'] : $data;
        if (in_array(strtolower((string) data_get($message, 'status')), ['failed', 'canceled', 'cancelled'])) {
            throw new UazapiRejectedException(422);
        }
        if (! filled(data_get($message, 'id')) && ! filled(data_get($message, 'messageid'))) {
            throw new RuntimeException('A UAZAPI ainda não confirmou o envio.');
        }

        return $data;
    }

    private function send($response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('A UAZAPI não concluiu a solicitação. Verifique a conexão e tente novamente.');
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('A UAZAPI retornou uma resposta inválida.');
        }

        return $data;
    }
}
