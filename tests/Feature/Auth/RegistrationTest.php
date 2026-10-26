<?php

use Laravel\Fortify\Features;

test('public registration routes and links are unavailable when disabled', function () {
    if (Features::enabled(Features::registration())) {
        $this->markTestSkipped('Public registration is enabled.');
    }

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    $this->get('/')->assertInertia(fn ($page) => $page
        ->component('welcome')
        ->where('canRegister', false));
    $this->get('/login')->assertInertia(fn ($page) => $page
        ->component('auth/login')
        ->where('canRegister', false));
});

test('registration screen can be rendered', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->get(route('register'));

    $response->assertOk();
    $this->get('/')->assertInertia(fn ($page) => $page
        ->component('welcome')
        ->where('canRegister', true));
    $this->get('/login')->assertInertia(fn ($page) => $page
        ->component('auth/login')
        ->where('canRegister', true));
});

test('new users can register', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});
