<?php

namespace Database\Factories;

use App\Models\UazapiConnection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UazapiConnection>
 */
class UazapiConnectionFactory extends Factory
{
    protected $model = UazapiConnection::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'instance_id' => (string) Str::uuid(),
            'instance_name' => 'test-'.Str::lower(Str::random(12)),
            'instance_token' => Str::random(40),
            'status' => 'connected',
            'webhook_secret_hash' => null,
            'phone' => '5511999999999',
            'connected_at' => now(),
        ];
    }
}
