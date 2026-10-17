<?php

namespace App\Jobs\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Compatibility with import jobs still serialized in the queue at rollout. */
class ImportChats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $connectionId, public int $offset)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // General chat import has been retired.
    }
}
