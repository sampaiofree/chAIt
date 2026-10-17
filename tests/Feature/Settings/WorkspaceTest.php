<?php

use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('workspace admin can view and rename the current workspace without changing its slug', function () {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $slug = $workspace->slug;

    $this->actingAs($admin)->get(route('workspace.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/workspace')
            ->where('workspace.name', $workspace->name));

    $this->actingAs($admin)->post(route('workspace.update'), ['name' => 'Novo Workspace'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($workspace->fresh()->name)->toBe('Novo Workspace')
        ->and($workspace->fresh()->slug)->toBe($slug);

    $this->actingAs($admin)->get(route('workspace.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentWorkspace.name', 'Novo Workspace')
            ->where('workspaces.0.name', 'Novo Workspace'));
});

test('workspace admin can upload, replace and remove an icon', function () {
    Storage::fake('public');
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;

    $this->actingAs($admin)->post(route('workspace.update'), [
        'name' => $workspace->name,
        'icon' => UploadedFile::fake()->image('first.png'),
    ])->assertSessionHasNoErrors();

    $first = $workspace->fresh()->icon_path;
    Storage::disk('public')->assertExists($first);
    expect($workspace->fresh()->icon_url)->toContain($first);
    $this->actingAs($admin)->get(route('workspace.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('workspace.icon_url', $workspace->fresh()->icon_url)
            ->where('currentWorkspace.icon_url', $workspace->fresh()->icon_url)
            ->where('workspaces.0.icon_url', $workspace->fresh()->icon_url));

    $this->actingAs($admin)->post(route('workspace.update'), [
        'name' => $workspace->name,
        'icon' => UploadedFile::fake()->image('second.jpg'),
    ])->assertSessionHasNoErrors();

    $second = $workspace->fresh()->icon_path;
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    $this->actingAs($admin)->delete(route('workspace.icon.destroy'))
        ->assertSessionHasNoErrors();

    expect($workspace->fresh()->icon_path)->toBeNull();
    Storage::disk('public')->assertMissing($second);
});

test('workspace icon and name are validated', function () {
    Storage::fake('public');
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;

    $this->actingAs($admin)->post(route('workspace.update'), [
        'name' => '',
        'icon' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml'),
    ])->assertSessionHasErrors(['name', 'icon']);

    $this->actingAs($admin)->post(route('workspace.update'), [
        'name' => 'Valido',
        'icon' => UploadedFile::fake()->image('large.png')->size(2049),
    ])->assertSessionHasErrors('icon');

    expect($workspace->fresh()->name)->toBe($workspace->name)
        ->and($workspace->fresh()->icon_path)->toBeNull();
});

test('non-admin cannot view or change workspace settings', function () {
    Storage::fake('public');
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $agent = User::factory()->create();
    WorkspaceMembership::query()->where('user_id', $agent->id)->delete();
    WorkspaceMembership::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $agent->id,
        'role' => 'agent',
        'joined_at' => now(),
    ]);
    $agent->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();

    $this->actingAs($agent)->get(route('workspace.edit'))->assertForbidden();
    $this->actingAs($agent)->post(route('workspace.update'), [
        'name' => 'Invasor',
        'icon' => UploadedFile::fake()->image('icon.png'),
    ])->assertForbidden();
    $this->actingAs($agent)->delete(route('workspace.icon.destroy'))->assertForbidden();

    expect($workspace->fresh()->name)->not->toBe('Invasor')
        ->and($workspace->fresh()->icon_path)->toBeNull();
});
