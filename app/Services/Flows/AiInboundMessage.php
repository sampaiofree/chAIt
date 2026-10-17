<?php

namespace App\Services\Flows;

use App\Models\AiSession;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiDeferredException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AiInboundMessage
{
    public function __construct(private UazapiClient $uazapi, private OpenAiClient $openai) {}

    public function input(AiSession $session, string $messageId, string $key, ?AiTurnBudget $budget = null, ?array $source = null): array
    {
        if (isset($source['prepared_error'])) {
            throw new UnsupportedAiMediaException('Mídia indisponível.');
        }
        if (isset($source['prepared_input'])) {
            $input = $source['prepared_input'];
            foreach ($input['content'] as &$part) {
                if (isset($part['local_file'])) {
                    $local = $part['local_file'];
                    $part[$local['field']] = 'data:'.$local['mime'].';base64,'.base64_encode(Storage::disk('local')->get($local['path']));
                    unset($part['local_file']);
                }
            }

            return $input;
        }
        $source = $source['message'] ?? $source;
        $execution = $session->execution()->with(['thread', 'connection'])->firstOrFail();
        try {
            $found = $source ? ['messages' => [$source]] : $this->uazapi->findMessage($execution->connection, $messageId, $budget);
        } catch (UazapiDeferredException $exception) {
            throw $exception;
        } catch (AiTurnLimitException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new UnsupportedAiMediaException('Mensagem indisponível na UAZAPI.');
        }
        $messages = $found['messages'] ?? $found['data'] ?? $found['items'] ?? (isset($found['id']) ? [$found] : []);
        $message = collect($messages)->first(fn ($item) => (string) (data_get($item, 'id') ?? data_get($item, 'messageid') ?? data_get($item, 'key.id')) === $messageId);
        if (! is_array($message) || (bool) (data_get($message, 'fromMe') ?? data_get($message, 'key.fromMe') ?? false)
            || (string) (data_get($message, 'chatid') ?? data_get($message, 'key.remoteJid')) !== (string) $execution->thread->external_chat_id) {
            throw new UnsupportedAiMediaException('Mensagem não encontrada na conversa da sessão.');
        }
        $reply = app(FlowContentButton::class)->resolve($execution->connection, $execution->contact_id, $message);
        if ($reply) {
            return ['video' => false, 'content' => [['type' => 'input_text', 'text' => $reply['text']]]];
        }
        $type = strtolower((string) (data_get($message, 'messageType') ?? data_get($message, 'type') ?? 'text'));
        $content = data_get($message, 'content');
        if (is_string($content)) {
            $content = json_decode($content, true) ?: $content;
        }
        $caption = (string) (data_get($message, 'text') ?? data_get($message, 'caption') ?? data_get($content, 'text')
            ?? data_get($content, 'conversation') ?? data_get($content, 'extendedTextMessage.text')
            ?? data_get($content, 'imageMessage.caption') ?? data_get($content, 'documentMessage.caption') ?? '');
        $mime = strtolower((string) (data_get($message, 'mimetype') ?? data_get($content, 'mimetype')
            ?? data_get($content, 'imageMessage.mimetype') ?? data_get($content, 'audioMessage.mimetype')
            ?? data_get($content, 'documentMessage.mimetype') ?? ''));
        if (str_contains($type, 'video') || str_starts_with($mime, 'video/')) {
            return ['video' => true, 'content' => []];
        }
        if (in_array($type, ['text', 'conversation', 'extendedtextmessage', 'extended_text_message'], true)) {
            return ['video' => false, 'content' => [['type' => 'input_text', 'text' => $caption]]];
        }
        try {
            $url = (string) data_get($this->uazapi->downloadMessage($execution->connection, $messageId, $budget), 'fileURL');
        } catch (UazapiDeferredException $exception) {
            throw $exception;
        } catch (AiTurnLimitException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new UnsupportedAiMediaException('Mídia indisponível na UAZAPI.');
        }
        $urlHost = parse_url($url, PHP_URL_HOST);
        $providerHost = parse_url((string) config('services.uazapi.base_url'), PHP_URL_HOST);
        $providerScheme = parse_url((string) config('services.uazapi.base_url'), PHP_URL_SCHEME);
        $urlScheme = parse_url($url, PHP_URL_SCHEME);
        $providerUrl = $urlHost && $urlHost === $providerHost && $urlScheme === $providerScheme;
        if ($urlScheme !== 'https' && ! $providerUrl) {
            throw new UnsupportedAiMediaException('Mídia indisponível para a IA.');
        }
        $ip = $urlHost ? gethostbyname($urlHost) : '';
        if (! $providerUrl && ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new UnsupportedAiMediaException('Endereço de mídia inválido.');
        }
        try {
            $temporary = tempnam(sys_get_temp_dir(), 'chait-ai-');
            $download = Http::withOptions([
                'sink' => $temporary,
                'on_headers' => function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 10 * 1024 * 1024) {
                        throw new \RuntimeException('media_too_large');
                    }
                },
                'progress' => function ($total, $downloaded): void {
                    if ($downloaded > 10 * 1024 * 1024) {
                        throw new \RuntimeException('media_too_large');
                    }
                },
            ])->timeout($budget?->timeout(25) ?? 25)->withoutRedirecting()->get($url);
        } catch (UazapiDeferredException $exception) {
            throw $exception;
        } catch (AiTurnLimitException $exception) {
            throw $exception;
        } catch (\Throwable) {
            if (isset($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            throw new UnsupportedAiMediaException('Mídia indisponível para a IA.');
        }
        $bytes = isset($temporary) && filesize($temporary) > 0 ? file_get_contents($temporary, false, null, 0, 10 * 1024 * 1024 + 1) : $download->body();
        if (isset($temporary) && is_file($temporary)) {
            unlink($temporary);
        }
        if (! $download->successful() || strlen($bytes) > 10 * 1024 * 1024) {
            throw new UnsupportedAiMediaException('Mídia indisponível ou acima de 10 MB.');
        }
        if (str_contains($type, 'image') || str_starts_with($mime, 'image/')) {
            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                throw new UnsupportedAiMediaException('Formato de imagem não aceito.');
            }

            return ['video' => false, 'content' => array_values(array_filter([
                $caption !== '' ? ['type' => 'input_text', 'text' => $caption] : null,
                ['type' => 'input_image', 'image_url' => 'data:'.$mime.';base64,'.base64_encode($bytes)],
            ]))];
        }
        if (str_contains($type, 'audio') || str_contains($type, 'ptt') || str_starts_with($mime, 'audio/')) {
            $extension = match ($mime) {
                'audio/ogg', 'application/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/webm' => 'webm', default => 'mp3'
            };
            $transcript = $this->openai->transcribe($key, $bytes, 'mensagem.'.$extension, $mime ?: 'audio/mpeg', $budget);

            return ['video' => false, 'content' => [['type' => 'input_text', 'text' => "Áudio do lead (transcrição): {$transcript}"]]];
        }
        $filename = (string) (data_get($message, 'fileName') ?? data_get($message, 'filename')
            ?? data_get($content, 'documentMessage.fileName') ?? 'documento');
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, ['pdf', 'docx', 'txt'], true)) {
            throw new UnsupportedAiMediaException('Formato de documento não aceito.');
        }

        return ['video' => false, 'content' => array_values(array_filter([
            $caption !== '' ? ['type' => 'input_text', 'text' => $caption] : null,
            ['type' => 'input_file', 'filename' => $filename, 'file_data' => 'data:'.($mime ?: 'application/octet-stream').';base64,'.base64_encode($bytes)],
        ]))];
    }
}
