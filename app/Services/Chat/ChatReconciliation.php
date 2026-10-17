<?php

namespace App\Services\Chat;

use App\Jobs\Chat\ImportChats;
use App\Models\UazapiConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatReconciliation
{
    public function start(UazapiConnection $candidate, bool $manualRetry = false): UazapiConnection
    {
        if (! $this->shouldStart($candidate, $manualRetry)) {
            return $candidate;
        }

        return DB::transaction(function () use ($candidate, $manualRetry): UazapiConnection {
            $connection = UazapiConnection::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! $this->shouldStart($connection, $manualRetry)) {
                return $connection;
            }

            $status = $connection->chat_import_status;
            $offset = $status === 'complete' ? 0 : $connection->chat_import_offset;
            $connection->update([
                'chat_import_status' => 'running',
                'chat_import_offset' => $offset,
                'chat_import_dispatched_at' => now(),
                'chat_import_attempts' => 0,
            ]);
            DB::afterCommit(function () use ($connection, $offset): void {
                try {
                    ImportChats::dispatch($connection->id, $offset);
                } catch (\Throwable) {
                    // The durable running marker is recovered by chat:recover.
                    Log::warning('Chat reconciliation dispatch deferred.', ['connection_id' => $connection->id]);
                }
            });

            return $connection;
        });
    }

    private function shouldStart(UazapiConnection $connection, bool $manualRetry): bool
    {
        if ($connection->status !== 'connected' || $connection->chat_import_status === 'running') {
            return false;
        }
        if ($connection->chat_import_status === 'complete') {
            return ! $connection->chat_last_reconciled_at || $connection->chat_last_reconciled_at->lte(now()->subDay());
        }
        if ($connection->chat_import_status === 'failed' && ! $manualRetry) {
            return ! $connection->chat_import_dispatched_at || $connection->chat_import_dispatched_at->lte(now()->subMinutes(5));
        }

        return true;
    }
}
