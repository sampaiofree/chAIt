<?php

use App\Models\User;
use Illuminate\Support\Facades\Vite;
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

test('dashboard document bounds response headers without removing HTML preloads', function () {
    // Exercise the production manifest even while the local Vite server is running.
    Vite::useHotFile(storage_path('framework/testing/no-vite-hot-file'));

    $response = $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

    $htmlPreloads = preg_match_all('/<link\s[^>]*rel="(?:preload|modulepreload)"/', $response->getContent());
    $link = implode(', ', $response->headers->all('link'));

    expect($htmlPreloads)->toBeGreaterThan(10)
        ->and(substr_count($link, '>;'))->toBeGreaterThan(0)->toBeLessThanOrEqual(10)
        ->and(strlen($link))->toBeLessThan(2048)
        ->and(strlen((string) $response->headers))->toBeLessThan(4096);
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
