<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sidebarOpen', false));
});

test('sidebar preference follows the saved cookie', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->withUnencryptedCookie('sidebar_state', 'true')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('sidebarOpen', true));

    $this->withUnencryptedCookie('sidebar_state', 'false')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('sidebarOpen', false));
});
