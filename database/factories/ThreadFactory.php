<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    protected $model = Thread::class;

    public function definition(): array
    {
        $workspace = Workspace::factory();

        return [
            'workspace_id' => $workspace,
            'uazapi_connection_id' => UazapiConnection::factory()->for($workspace),
            'contact_id' => Contact::factory()->for($workspace),
            'assigned_to_user_id' => User::factory(),
            'channel' => 'whatsapp',
            'status' => 'open',
            'priority' => 'normal',
            'unread_count' => fake()->numberBetween(0, 4),
            'last_message_at' => now()->subMinutes(fake()->numberBetween(1, 120)),
            'last_inbound_at' => now()->subMinutes(fake()->numberBetween(1, 120)),
            'last_outbound_at' => now()->subMinutes(fake()->numberBetween(1, 120)),
            'customer_service_window_expires_at' => now()->addHours(23),
            'free_entry_point_expires_at' => null,
            'last_pricing_type' => null,
            'last_pricing_category' => null,
            'metadata' => [],
        ];
    }

    public function templateOnly(): static
    {
        return $this->state(fn () => [
            'customer_service_window_expires_at' => now()->subHour(),
            'free_entry_point_expires_at' => null,
        ]);
    }
}
