<?php

namespace App\Services\Chat;

use App\Models\UazapiConnection;
use App\Models\Workspace;
use App\Services\Uazapi\UazapiClient;
use Illuminate\Support\Facades\Cache;

class ChatConnectionAccess
{
    public function state(Workspace $workspace): array
    {
        $connection = UazapiConnection::where('workspace_id', $workspace->id)->first();
        if (! $connection || ! $connection->instance_token) {
            return ['allowed' => false, 'status' => 'disconnected'];
        }
        $result = Cache::get('chat-access:'.$connection->id);
        if ($result && $connection->status !== 'connected' && $result['allowed']) {
            $result = null;
        }
        if ($result) {
            return $result;
        }
        $lock = Cache::lock('chat-access-check:'.$connection->id, 15);
        if (! $lock->get()) {
            return ['allowed' => false, 'status' => 'checking'];
        }
        try {
            $data = app(UazapiClient::class)->status($connection);
            $raw = strtolower((string) (data_get($data, 'instance.status') ?? data_get($data, 'status.instance.status') ?? (is_string($data['status'] ?? null) ? $data['status'] : 'disconnected')));
            $connected = (bool) (data_get($data, 'status.connected') ?? in_array($raw, ['connected', 'open', 'online'], true));
            $connection->update(['status' => $connected ? 'connected' : 'disconnected', 'provider_checked_at' => now(), 'activated_at' => $connected ? ($connection->activated_at ?? now()) : $connection->activated_at]);
            $result = ['allowed' => $connected, 'status' => $connected ? 'connected' : 'disconnected'];
        } catch (\Throwable) {
            $result = ['allowed' => false, 'status' => 'unavailable'];
        } finally {
            $lock->release();
        }
        Cache::put('chat-access:'.$connection->id, $result, 15);

        return $result;
    }
}
