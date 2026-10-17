<?php

namespace App\Jobs\Chat;

use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Services\Chat\ChatProjection;
use App\Services\Chat\ChatPublisher;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiDeferredException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ImportChats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $timeout = 90;

    public function __construct(public int $connectionId, public int $offset)
    {
        $this->onQueue('default');
    }

    public function handle(UazapiClient $client, ChatProjection $projection, ChatPublisher $publisher): void
    {
        $lock = Cache::lock('chat-import:'.$this->connectionId, 110);
        if (! $lock->get()) {
            $this->release(1);

            return;
        }
        try {
            $connection = UazapiConnection::find($this->connectionId);
            if (! $connection || $connection->chat_import_offset !== $this->offset || $connection->chat_import_status !== 'running') {
                return;
            }
            if ($connection->status !== 'connected') {
                $connection->update(['chat_import_status' => 'failed', 'chat_import_dispatched_at' => now()]);

                return;
            }
            $response = $client->chats($connection, $this->offset, 200);
            $items = $response['chats'] ?? $response['data'] ?? $response['items'] ?? [];
            if (! is_array($items) || ! array_is_list($items)) {
                throw new \RuntimeException('invalid_chat_page');
            }
            foreach ($items as $chat) {
                if (is_array($chat)) {
                    Thread::withoutEvents(fn () => $projection->sync($connection, $chat));
                }
            }
            $complete = count($items) < 200;
            $connection->update([
                'chat_import_offset' => $this->offset + count($items),
                'chat_import_status' => $complete ? 'complete' : 'running',
                'chat_import_dispatched_at' => $complete ? null : now(),
                'chat_import_attempts' => 0,
                ...($complete ? ['chat_last_reconciled_at' => now()] : []),
            ]);
            $publisher->send($connection->workspace_id, 'import.completed', ['offset' => $connection->chat_import_offset, 'complete' => $connection->chat_import_status === 'complete']);
            if (! $complete) {
                try {
                    self::dispatch($connection->id, $connection->chat_import_offset);
                } catch (\Throwable) {
                    // chat:recover resumes this page from the persisted offset.
                    Log::warning('Chat reconciliation page dispatch deferred.', ['connection_id' => $connection->id]);
                }
            }
        } catch (UazapiDeferredException $error) {
            if (isset($connection) && $error->reason === 'read_error') {
                $connection->increment('chat_import_attempts');
                if ($connection->chat_import_attempts >= 3) {
                    $connection->update(['chat_import_status' => 'failed', 'chat_import_dispatched_at' => now()]);
                    $publisher->send($connection->workspace_id, 'import.failed', ['error' => 'Não foi possível carregar mais conversas.']);

                    return;
                }
                $this->release((2 ** $connection->chat_import_attempts) + random_int(0, 1));
            } else {
                $this->release($error->delay);
            }
        } catch (\Throwable $error) {
            UazapiConnection::whereKey($this->connectionId)->where('chat_import_status', 'running')->where('chat_import_offset', $this->offset)->update(['chat_import_status' => 'failed', 'chat_import_dispatched_at' => now()]);
            Log::warning('Chat import failed.', ['connection_id' => $this->connectionId, 'exception_type' => $error::class]);
            if (isset($connection)) {
                $publisher->send($connection->workspace_id, 'import.failed', ['error' => 'Não foi possível carregar mais conversas. Tente novamente.']);
            }
        } finally {
            $lock->release();
        }
    }
}
