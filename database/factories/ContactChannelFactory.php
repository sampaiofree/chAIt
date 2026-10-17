<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactChannel>
 */
class ContactChannelFactory extends Factory
{
    protected $model = ContactChannel::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'contact_id' => Contact::factory(),
            'type' => 'whatsapp',
            'external_id' => '55'.fake()->numberBetween(1111111111, 9999999999),
            'handle' => '+55119'.fake()->numberBetween(10000000, 99999999),
            'metadata' => [],
        ];
    }
}
