<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('appearance setting defaults to system and is shared with the page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/appearance')
            ->where('appearance', 'system'));
});

test('a user can save their own appearance preference', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('appearance.update'), ['appearance' => 'dark'])
        ->assertRedirect(route('appearance.edit'));

    expect($user->fresh()->appearance)->toBe('dark')
        ->and($other->fresh()->appearance)->toBe('system');
});

test('the account preference overrides the legacy appearance cookie in the html shell', function () {
    $user = User::factory()->create(['appearance' => 'dark']);

    $this->actingAs($user)
        ->withCookie('appearance', 'light')
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('<html lang="en" data-appearance="dark" class="dark">', false);
});

test('public pages use the system theme even when a legacy appearance cookie exists', function () {
    $this->withCookie('appearance', 'dark')
        ->get(route('home'))
        ->assertOk()
        ->assertSee('data-appearance="system"', false);
});

test('appearance setting only accepts supported values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('appearance.update'), ['appearance' => 'sepia'])
        ->assertSessionHasErrors('appearance');

    expect($user->fresh()->appearance)->toBe('system');
});

test('appearance setting requires authentication', function () {
    $this->patch(route('appearance.update'), ['appearance' => 'dark'])
        ->assertRedirect(route('login'));
});
