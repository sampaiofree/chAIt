<?php

namespace App\Jobs\Chat;

use App\Jobs\ProcessAiTurn;
use App\Models\AiSession;
use App\Models\UazapiInbox;
use App\Models\WorkspaceOpenAiIntegration;
use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Flows\AiInboundMessage;
use App\Services\Flows\AiTurnBudget;
use App\Services\Uazapi\UazapiDeferredException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrepareAiInput implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $timeout = 180;

    public function __construct(public int $sessionId, public string $messageId, public int $inboxId)
    {
        $this->onQueue('media');
    }

    public function workspaceId(): ?int
    {
        return UazapiInbox::whereKey($this->inboxId)->value('workspace_id');
    }

    public function middleware(): array
    {
        return [new WorkspaceCapacity('media')];
    }

    public function handle(AiInboundMessage $inbound): void
    {
        $lock = Cache::lock('chat-ai-input:'.$this->inboxId, 200);
        if (! $lock->get()) {
            $this->release(1);

            return;
        }
        $inbox = UazapiInbox::find($this->inboxId);
        try {
            if (! $inbox || ! $inbox->payload || $inbox->expires_at->isPast()) {
                return;
            }
            $payload = $inbox->payload;
            if (! isset($payload['prepared_input'])) {
                $session = AiSession::findOrFail($this->sessionId);
                if ($session->status !== 'active') {
                    $inbox->consumed();

                    return;
                }
                $key = WorkspaceOpenAiIntegration::where('workspace_id', $session->workspace_id)->firstOrFail()->api_key;
                $input = $inbound->input($session, $this->messageId, $key, new AiTurnBudget, $payload['message']);
                foreach ($input['content'] as &$part) {
                    foreach (['image_url', 'file_data'] as $field) {
                        if (isset($part[$field]) && preg_match('/^data:([^;]+);base64,(.*)$/s', $part[$field], $matches)) {
                            $path = 'chat-temporary/ai-'.Str::uuid();
                            Storage::disk('local')->put($path, base64_decode($matches[2], true));
                            unset($part[$field]);
                            $part['local_file'] = ['path' => $path, 'mime' => $matches[1], 'field' => $field];
                        }
                    }
                }
                $payload['prepared_input'] = $input;
                $inbox->update(['payload' => $payload]);
            }
            ProcessAiTurn::dispatch($this->sessionId, $this->messageId, $this->inboxId);
        } catch (UazapiDeferredException $error) {
            if ($inbox && $error->reason === 'read_error') {
                $payload = $inbox->fresh()->payload;
                $payload['media_attempts'] = ($payload['media_attempts'] ?? 0) + 1;
                if ($payload['media_attempts'] >= 3) {
                    $payload['prepared_error'] = true;
                }
                $inbox->update(['payload' => $payload]);
                if (isset($payload['prepared_error'])) {
                    ProcessAiTurn::dispatch($this->sessionId, $this->messageId, $this->inboxId);
                } else {
                    $this->release((2 ** $payload['media_attempts']) + random_int(0, 1));
                }
            } else {
                $this->release($error->delay);
            }
        } catch (\Throwable) {
            if ($inbox && $inbox->payload) {
                $payload = $inbox->payload;
                $payload['prepared_error'] = true;
                $inbox->update(['payload' => $payload]);
                ProcessAiTurn::dispatch($this->sessionId, $this->messageId, $this->inboxId);
            }
        } finally {
            $lock->release();
        }
    }
}
