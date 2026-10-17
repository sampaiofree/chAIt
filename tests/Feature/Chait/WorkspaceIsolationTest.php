<?php

use App\Models\User;

test('users automatically receive a default workspace', function () {
    $user = User::factory()->create();

    expect($user->fresh()->currentWorkspace)->not->toBeNull()
        ->and($user->availableWorkspaces())->toHaveCount(1)
        ->and($user->availableWorkspaces()->first()->pivot->role)->toBe('owner');
});
