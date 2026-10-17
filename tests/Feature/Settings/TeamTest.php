<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Hash;

function teamMemberPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Equipe Nova',
        'email' => 'equipe@example.test',
        'phone' => '5511999999999',
        'password' => 'senha-inicial-segura-123',
        'password_confirmation' => 'senha-inicial-segura-123',
        'can_view_unassigned' => true,
        'can_manage_flows' => false,
    ], $overrides);
}

test('workspace admin creates an operational user in the current workspace without an extra workspace', function () {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $workspaceCount = Workspace::query()->count();

    $this->actingAs($admin)->post(route('workspaces.members.store', $workspace), teamMemberPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $member = User::query()->where('email', 'equipe@example.test')->sole();
    $membership = $member->membershipFor($workspace);

    expect(Workspace::query()->count())->toBe($workspaceCount)
        ->and($member->phone)->toBe('5511999999999')
        ->and($member->current_workspace_id)->toBe($workspace->id)
        ->and($membership)->not->toBeNull()
        ->and($membership->role)->toBe('agent')
        ->and($membership->can_view_unassigned)->toBeTrue()
        ->and($membership->can_manage_flows)->toBeFalse()
        ->and(Hash::check('senha-inicial-segura-123', $member->password))->toBeTrue();
});

test('workspace admin links an existing account without changing its account data', function () {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $existing = User::factory()->create(['name' => 'Nome Original', 'email' => 'existing@example.test']);
    $existingWorkspaceId = $existing->current_workspace_id;
    $existingPassword = $existing->password;

    $this->actingAs($admin)->post(route('workspaces.members.store', $workspace), teamMemberPayload([
        'name' => '',
        'email' => 'EXISTING@example.test',
        'password' => '',
        'password_confirmation' => '',
        'can_view_unassigned' => false,
        'can_manage_flows' => true,
        'role' => 'owner',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect($existing->fresh()->name)->toBe('Nome Original')
        ->and($existing->fresh()->password)->toBe($existingPassword)
        ->and($existing->fresh()->current_workspace_id)->toBe($existingWorkspaceId)
        ->and($existing->membershipFor($workspace)->role)->toBe('agent')
        ->and($existing->membershipFor($workspace)->can_view_unassigned)->toBeFalse()
        ->and($existing->membershipFor($workspace)->can_manage_flows)->toBeTrue();
});

test('team creation is restricted to workspace admins and cannot write to another workspace', function () {
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $otherWorkspace = Workspace::factory()->create();
    $agent = User::factory()->create();
    $agent->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
    WorkspaceMembership::query()->where('user_id', $agent->id)->delete();
    WorkspaceMembership::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $agent->id,
        'role' => 'agent',
        'joined_at' => now(),
    ]);

    $this->actingAs($agent)->post(route('workspaces.members.store', $workspace), teamMemberPayload())->assertForbidden();
    $this->actingAs($owner)->post(route('workspaces.members.store', $otherWorkspace), teamMemberPayload())->assertForbidden();

    expect(User::query()->where('email', 'equipe@example.test')->exists())->toBeFalse();
});

test('new accounts must use the configured password rules', function () {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $workspaceCount = Workspace::query()->count();

    $this->actingAs($admin)->post(route('workspaces.members.store', $workspace), teamMemberPayload([
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))->assertSessionHasErrors('password');

    expect(User::query()->where('email', 'equipe@example.test')->exists())->toBeFalse()
        ->and(Workspace::query()->count())->toBe($workspaceCount);
});

test('new accounts require a name and password but existing accounts do not', function () {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;

    $this->actingAs($admin)->post(route('workspaces.members.store', $workspace), teamMemberPayload([
        'name' => '',
        'password' => '',
        'password_confirmation' => '',
    ]))->assertSessionHasErrors(['name', 'password']);

    expect(User::query()->where('email', 'equipe@example.test')->exists())->toBeFalse();
});

test('adding an account already in the workspace returns a validation error', function () {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $member = User::factory()->create(['email' => 'member@example.test']);
    $workspace->memberships()->create([
        'user_id' => $member->id,
        'role' => 'agent',
        'joined_at' => now(),
    ]);

    $this->actingAs($admin)->post(route('workspaces.members.store', $workspace), teamMemberPayload([
        'email' => 'member@example.test',
    ]))->assertSessionHasErrors('email');

    expect($workspace->memberships()->where('user_id', $member->id)->count())->toBe(1);
});

test('a user can switch only to a workspace they belong to', function () {
    $user = User::factory()->create();
    $firstWorkspace = $user->fresh()->currentWorkspace;
    $secondWorkspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $secondWorkspace->memberships()->create([
        'user_id' => $user->id,
        'role' => 'agent',
        'joined_at' => now(),
    ]);

    $this->actingAs($user)->post(route('workspaces.current', $secondWorkspace))
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->current_workspace_id)->toBe($secondWorkspace->id);

    $this->post(route('workspaces.current', $otherWorkspace))->assertForbidden();

    expect($user->fresh()->current_workspace_id)->toBe($secondWorkspace->id)
        ->and($firstWorkspace->memberships()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('new team accounts require a valid international WhatsApp number atomically', function (mixed $phone) {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $userCount = User::count();
    $workspaceCount = Workspace::count();
    $membershipCount = WorkspaceMembership::count();

    $this->actingAs($admin)->post(route('workspaces.members.store', $workspace), teamMemberPayload([
        'phone' => $phone,
    ]))->assertSessionHasErrors('phone');

    expect(User::count())->toBe($userCount)
        ->and(Workspace::count())->toBe($workspaceCount)
        ->and(WorkspaceMembership::count())->toBe($membershipCount);
})->with('invalid international phones');

test('new team accounts require the WhatsApp field even when omitted', function () {
    $admin = User::factory()->create();
    $data = teamMemberPayload();
    unset($data['phone']);

    $this->actingAs($admin)->post(route('workspaces.members.store', $admin->fresh()->currentWorkspace), $data)
        ->assertSessionHasErrors('phone');

    expect(User::where('email', $data['email'])->exists())->toBeFalse();
});

test('new team accounts accept non Brazilian international WhatsApp numbers', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->post(route('workspaces.members.store', $admin->fresh()->currentWorkspace), teamMemberPayload([
        'phone' => '41446681800',
    ]))->assertSessionHasNoErrors();

    expect(User::where('email', 'equipe@example.test')->sole()->phone)->toBe('41446681800');
});

test('linking an existing account ignores all account fields and preserves its phone', function (?string $savedPhone, mixed $submittedPhone) {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $existing = User::factory()->create(['phone' => $savedPhone]);
    $original = $existing->fresh()->getAttributes();

    $data = teamMemberPayload([
        'email' => strtoupper($existing->email),
        'name' => ['invalid'],
        'password' => ['invalid'],
        'password_confirmation' => ['invalid'],
        'phone' => $submittedPhone,
    ]);
    if ($submittedPhone === null) {
        unset($data['phone']);
    }

    $this->actingAs($admin)->post(route('workspaces.members.store', $workspace), $data)
        ->assertSessionHasNoErrors();

    expect($existing->fresh()->getAttributes())->toBe($original)
        ->and($existing->membershipFor($workspace))->not->toBeNull();
})->with([
    'no saved phone' => [null],
    'saved phone' => ['5511999999999'],
])->with([
    'omitted phone' => [null],
    'different phone' => ['41446681800'],
    'malformed phone' => [['invalid']],
]);
