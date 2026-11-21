<?php

use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;

beforeEach(function () {
    config(['fortify.features' => [...config('fortify.features'), Features::registration()]]);
});

test('public registration routes and links are unavailable when disabled', function () {
    config(['fortify.features' => array_values(array_diff(config('fortify.features'), [Features::registration()]))]);

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    $this->get('/')->assertInertia(fn ($page) => $page
        ->component('auth/login')
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
        ->component('auth/login')
        ->where('canRegister', true));
    $this->get('/login')->assertInertia(fn ($page) => $page
        ->component('auth/login')
        ->where('canRegister', true));
});

test('new users can register', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    Mail::fake();
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => true,
    ]);

    $this->assertGuest();
    $response->assertRedirect('/register/confirm');
});

test('registration enforces the six character minimum', function (string $password, bool $valid) {
    $this->skipUnlessFortifyHas(Features::registration());

    Mail::fake();
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => $password,
        'password_confirmation' => $password,
        'terms' => true,
    ]);

    if ($valid) {
        $response->assertSessionHasNoErrors();
        $this->assertGuest();
    } else {
        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }
})->with(['five characters' => ['abcde', false], 'six characters' => ['abcdef', true]]);
