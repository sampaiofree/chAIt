<?php

namespace App\Jobs;

use App\Jobs\Chat\PrepareAiInput;
use App\Models\AiSession;
use App\Models\UazapiInbox;
use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Flows\AiSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessAiTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 180;

    public function __construct(public int $sessionId, public ?string $providerMessageId = null, public ?int $inboxId = null)
    {
        if (config('chat.enabled')) {
            $this->tries = 0;
        }
        $this->onQueue(config('chat.enabled') ? 'ai' : 'automations');
    }

    public function middleware(): array
    {
        return [new WorkspaceCapacity('ai')];
    }

    public function handle(AiSessionService $sessions): void
    {
        $inbox = $this->inboxId ? UazapiInbox::find($this->inboxId) : null;
        if ($this->inboxId && (! $inbox?->payload || $inbox->expires_at->isPast())) {
            return;
        }
        if ($inbox) {
            $payload = $inbox->payload;
            $type = strtolower((string) data_get($payload, 'message.messageType', data_get($payload, 'message.type', 'text')));
            if (! in_array($type, ['text', 'conversation', 'extendedtextmessage', 'extended_text_message', 'buttonsresponsemessage'], true) && ! isset($payload['prepared_input']) && ! isset($payload['prepared_error'])) {
                PrepareAiInput::dispatch($this->sessionId, $this->providerMessageId, $this->inboxId);

                return;
            }
        }
        $sessions->process($this->sessionId, $this->providerMessageId, $this->inboxId);
        if ($inbox && (DB::table('ai_session_inputs')->where('ai_session_id', $this->sessionId)->where('provider_message_id', $this->providerMessageId)->exists() || AiSession::whereKey($this->sessionId)->value('status') !== 'active')) {
            $inbox->consumed();
        }
    }

    public function failed(\Throwable $exception): void
    {
        app(AiSessionService::class)->failById($this->sessionId, 'Falha inesperada no processamento IA.');
    }
}
