<?php

namespace Database\Factories;

use App\Enums\ThreadAssignmentState;
use App\Models\Thread;
use App\Models\ThreadAssignment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadAssignment>
 */
class ThreadAssignmentFactory extends Factory
{
    protected $model = ThreadAssignment::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'thread_id' => Thread::factory(),
            'assigned_to_user_id' => User::factory(),
            'assigned_by_user_id' => User::factory(),
            'state' => ThreadAssignmentState::Active->value,
            'reason' => null,
        ];
    }
}
