<?php

namespace App\Jobs;

use App\Services\Flows\OpenAiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanupDeletedLeadAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $conversationId, public string $encryptedKey)
    {
        $this->onQueue('ai');
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(OpenAiClient $client): void
    {
        if (! $client->cleanup(decrypt($this->encryptedKey), $this->conversationId)) {
            $this->release(30);
        }
    }
}
