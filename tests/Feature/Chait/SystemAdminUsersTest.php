<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function systemAdmin(): User
{
    return User::factory()->create(['email' => User::SYSTEM_ADMIN_EMAIL]);
}

function adminUserData(array $overrides = []): array
{
    return array_replace([
        'name' => 'Cliente Novo',
        'email' => 'cliente@example.com',
        'phone' => '5511999999999',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ], $overrides);
}

test('admin pages and writes require the system administrator', function () {
    $target = User::factory()->create();
    $this->get('/admin/usuarios')->assertRedirect('/login');
    $this->post('/admin/usuarios', adminUserData())->assertRedirect('/login');
    $this->get("/admin/usuarios/{$target->id}/editar")->assertRedirect('/login');
    $this->patch("/admin/usuarios/{$target->id}", adminUserData())->assertRedirect('/login');
    $this->actingAs($target)->get('/admin/usuarios')->assertForbidden();
    $this->post('/admin/usuarios', adminUserData())->assertForbidden();
    $this->get("/admin/usuarios/{$target->id}/editar")->assertForbidden();
    $this->patch("/admin/usuarios/{$target->id}", adminUserData())->assertForbidden();
    $this->get('/settings/profile')->assertInertia(fn (Assert $page) => $page->where('isSystemAdmin', false));
    $this->actingAs(systemAdmin())->get('/admin/usuarios')->assertOk()->assertInertia(fn (Assert $page) => $page->where('isSystemAdmin', true));
});

test('list filters owners, searches, paginates, and shows every workspace role', function () {
    $admin = systemAdmin();
    $owner = User::factory()->create(['name' => 'Zebra Owner', 'email' => 'zebra@example.com']);
    $other = User::factory()->create(['name' => 'Another Owner']);
    $member = User::factory()->create(['name' => 'Member Only', 'current_workspace_id' => $owner->current_workspace_id]);
    WorkspaceMembership::create(['workspace_id' => $owner->current_workspace_id, 'user_id' => $member->id, 'role' => 'agent']);
    WorkspaceMembership::create(['workspace_id' => $other->current_workspace_id, 'user_id' => $owner->id, 'role' => 'agent']);

    $this->actingAs($admin)->get('/admin/usuarios?search=zebra')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.filter', 'owners')->has('users.data', 1)->where('users.data.0.id', $owner->id)->has('users.data.0.workspaces', 2));
    $this->get('/admin/usuarios?search=Member')->assertInertia(fn (Assert $page) => $page->has('users.data', 0));
    $this->get('/admin/usuarios?filter=all&search=Member')->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.id', $member->id));
    User::factory()->count(21)->create();
    $this->get('/admin/usuarios?filter=all')->assertInertia(fn (Assert $page) => $page->has('users.data', 20)->where('users.current_page', 1)->where('users.last_page', 2));
});

test('admin creates an independent owner and preserves filters', function () {
    $admin = systemAdmin();
    $this->actingAs($admin)->post('/admin/usuarios?filter=all&search=cliente', adminUserData(['email' => 'CLIENTE@EXAMPLE.COM']))
        ->assertSessionHasNoErrors()->assertRedirect('/admin/usuarios?filter=all&search=cliente');
    $user = User::where('email', 'cliente@example.com')->firstOrFail();
    expect(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->current_workspace_id)->not->toBe($admin->current_workspace_id)
        ->and($user->memberships()->where('workspace_id', $user->current_workspace_id)->value('role'))->toBe('owner');
});

test('a failed workspace creation rolls back the account', function () {
    $admin = systemAdmin();
    Workspace::creating(fn () => throw new RuntimeException('Workspace failure'));
    try {
        $this->actingAs($admin)->withoutExceptionHandling()->post('/admin/usuarios', adminUserData());
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Workspace failure');
    } finally {
        Workspace::flushEventListeners();
    }
    $this->assertDatabaseMissing('users', ['email' => 'cliente@example.com']);
});

test('editing preserves memberships and keeps or changes password as requested', function () {
    $admin = systemAdmin();
    $target = User::factory()->create(['phone' => '5511999999999', 'password' => 'old-password']);
    $workspace = $target->current_workspace_id;
    $membership = $target->memberships()->firstOrFail();
    $this->actingAs($admin)->get("/admin/usuarios/{$target->id}/editar?filter=all&search=old")->assertOk();
    $this->patch("/admin/usuarios/{$target->id}?filter=all&search=old", adminUserData(['password' => '', 'password_confirmation' => '']))
        ->assertSessionHasNoErrors()->assertRedirect('/admin/usuarios?filter=all&search=old');
    expect(Hash::check('old-password', $target->fresh()->password))->toBeTrue()
        ->and($target->fresh()->current_workspace_id)->toBe($workspace)
        ->and($target->memberships()->sole()->id)->toBe($membership->id);
    $this->patch("/admin/usuarios/{$target->id}", adminUserData())->assertSessionHasNoErrors();
    expect(Hash::check('password123', $target->fresh()->password))->toBeTrue();
});

test('invalid input, duplicate emails and the reserved email are rejected', function () {
    $admin = systemAdmin();
    $target = User::factory()->create(['email' => 'Existing@Example.com']);
    $this->actingAs($admin)->post('/admin/usuarios', adminUserData(['email' => 'EXISTING@example.com']))->assertSessionHasErrors('email');
    $this->post('/admin/usuarios', adminUserData(['phone' => '123', 'password_confirmation' => 'bad']))->assertSessionHasErrors(['phone', 'password']);
    $this->post('/admin/usuarios', adminUserData(['email' => 'SAMPAIO.FREE@GMAIL.COM']))->assertSessionHasErrors('email');
    $this->patch("/admin/usuarios/{$admin->id}", adminUserData(['email' => 'other@example.com']))->assertSessionHasErrors('email');
    $this->actingAs($target)->patch('/settings/profile', ['name' => 'Test', 'phone' => '5511999999999', 'email' => 'SAMPAIO.FREE@GMAIL.COM'])->assertSessionHasErrors('email');
    $this->actingAs($admin)->patch('/settings/profile', ['name' => 'Admin', 'phone' => '5511999999999', 'email' => 'other@example.com'])->assertSessionHasErrors('email');
});

test('reserved email cannot be claimed through registration or team creation when no admin exists', function () {
    expect(fn () => app(CreateNewUser::class)->create(['name' => 'Other', 'email' => 'SAMPAIO.FREE@GMAIL.COM', 'password' => 'password123', 'password_confirmation' => 'password123']))
        ->toThrow(ValidationException::class);
    $owner = User::factory()->create();
    $this->actingAs($owner)->post("/workspaces/{$owner->current_workspace_id}/members", [
        ...adminUserData(['email' => 'SAMPAIO.FREE@GMAIL.COM']), 'can_view_unassigned' => false, 'can_manage_flows' => false,
    ])->assertSessionHasErrors('email');
    $this->assertDatabaseMissing('users', ['email' => User::SYSTEM_ADMIN_EMAIL]);
});
