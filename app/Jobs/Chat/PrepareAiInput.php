<?php

namespace App\Jobs\Chat;

use App\Jobs\ProcessAiTurn;
use App\Jobs\ProcessFlowMessageBatch;
use App\Models\AiSession;
use App\Models\FlowMessageBatch;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrepareAiInput implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $timeout = 180;

    public ?int $batchId = null;

    public function __construct(public int $sessionId, public string $messageId, public int $inboxId, public bool $opening = false, ?int $batchId = null)
    {
        $this->batchId = $batchId;
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
            if (($this->batchId && ! FlowMessageBatch::whereKey($this->batchId)->exists()) || ! $inbox || ! $inbox->payload || $inbox->expires_at->isPast()) {
                return;
            }
            $payload = $inbox->payload;
            if (! isset($payload['prepared_input']) && ! isset($payload['prepared_error'])) {
                $session = AiSession::findOrFail($this->sessionId);
                if ($session->status !== 'active') {
                    if (! $this->batchId) {
                        $inbox->consumed();
                    }

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
                unset($part);
                $payload['prepared_input'] = $input;
                if (! $this->storePayload($payload)) {
                    foreach ($input['content'] as $part) {
                        if (isset($part['local_file']['path'])) {
                            Storage::disk('local')->delete($part['local_file']['path']);
                        }
                    }

                    return;
                }
            }
            $this->resumeTurn();
        } catch (UazapiDeferredException $error) {
            if ($inbox && $error->reason === 'read_error') {
                $payload = $inbox->fresh()->payload;
                $payload['media_attempts'] = ($payload['media_attempts'] ?? 0) + 1;
                if ($payload['media_attempts'] >= 3) {
                    $payload['prepared_error'] = true;
                }
                if (! $this->storePayload($payload)) {
                    return;
                }
                if (isset($payload['prepared_error'])) {
                    $this->resumeTurn();
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
                if (! $this->storePayload($payload)) {
                    return;
                }
                $this->resumeTurn();
            }
        } finally {
            $lock->release();
        }
    }

    private function storePayload(array $payload): bool
    {
        return DB::transaction(function () use ($payload): bool {
            $inbox = UazapiInbox::whereKey($this->inboxId)->lockForUpdate()->first();
            if (! $inbox?->payload || $inbox->expires_at->isPast()
                || ($this->batchId && ! FlowMessageBatch::whereKey($this->batchId)->exists())) {
                return false;
            }

            return $inbox->update(['payload' => $payload]);
        }, 3);
    }

    private function resumeTurn(): void
    {
        if ($this->batchId) {
            ProcessFlowMessageBatch::dispatch($this->batchId, true);

            return;
        }

        ProcessAiTurn::dispatch($this->sessionId, $this->opening ? null : $this->messageId, $this->inboxId);
    }
}
