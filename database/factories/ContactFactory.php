<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'display_name' => fake()->name(),
            'phone' => '+55119'.fake()->numberBetween(10000000, 99999999),
            'avatar_url' => null,
            'last_seen_at' => now()->subMinutes(fake()->numberBetween(1, 240)),
            'profile' => [],
        ];
    }
}
