<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;

beforeEach(function () {
    config(['fortify.features' => [...config('fortify.features'), Features::registration()]]);
    Mail::fake();
});

test('public registration only creates a user after confirmation', function () {
    $this->post('/register', ['name' => 'Teste', 'email' => 'novo@example.com', 'password' => 'password', 'password_confirmation' => 'password', 'terms' => true])->assertRedirect('/register/confirm');
    $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
    DB::table('pending_registrations')->where('email', 'novo@example.com')->update(['code' => Hash::make('123456')]);
    $this->post('/register/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
    $this->post('/register/confirm', ['code' => '123456'])->assertRedirect('/login');
    $user = User::where('email', 'novo@example.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->current_workspace_id)->not->toBeNull();
    $this->post('/register/confirm', ['code' => '123456'])->assertSessionHasErrors('code');
});

test('blocked users cannot access an existing session', function () {
    $user = User::factory()->create();
    $user->forceFill(['access_blocked_at' => now()])->save();
    $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

test('public deletion confirmation blocks access and preserves the workspace', function () {
    $user = User::factory()->create();
    $workspaceId = $user->current_workspace_id;
    $this->post('/exclusao-de-conta', ['email' => $user->email])->assertRedirect();
    $secret = session('deletion_secret');
    $key = 'deletion:'.hash('sha256', $secret);
    $challenge = Cache::get($key);
    $challenge['code'] = Hash::make('123456');
    Cache::put($key, $challenge, now()->addMinutes(10));
    $this->post('/exclusao-de-conta/confirmar', ['code' => '000000'])->assertSessionHasErrors('code');
    expect($user->fresh()->access_blocked_at)->toBeNull();
    $this->post('/exclusao-de-conta/confirmar', ['code' => '123456'])->assertRedirect('/login');
    expect($user->fresh()->access_blocked_at)->not->toBeNull();
    $this->assertDatabaseHas('account_deletion_requests', ['user_id' => $user->id, 'origin' => 'web']);
    $this->assertDatabaseHas('workspaces', ['id' => $workspaceId]);
});
