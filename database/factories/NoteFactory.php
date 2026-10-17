<?php

namespace Database\Factories;

use App\Models\Note;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    protected $model = Note::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'thread_id' => Thread::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
