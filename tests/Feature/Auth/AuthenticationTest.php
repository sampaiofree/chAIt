<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;

test('home renders the login screen with the same options and status as login', function () {
    foreach (['home', 'login'] as $route) {
        $this->withSession(['status' => 'E-mail confirmado. Você já pode entrar.'])
            ->get(route($route))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/login')
                ->where('canResetPassword', Features::enabled(Features::resetPasswords()))
                ->where('canRegister', Features::enabled(Features::registration()))
                ->where('status', 'E-mail confirmado. Você já pode entrar.'));
    }
});

test('authenticated users visiting an entry page are redirected to the dashboard', function (string $route) {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertRedirect(route('dashboard'));
})->with(['home', 'login']);

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('persisted sessions resume authentication only while unexpired', function (bool $expired) {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $sessionId = Str::random(40);
    $payload = [auth()->guard()->getName() => $user->id];

    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->id,
        'payload' => base64_encode(config('session.serialization') === 'json' ? json_encode($payload) : serialize($payload)),
        'last_activity' => $expired ? now()->subMinutes(config('session.lifetime') + 1)->timestamp : now()->timestamp,
    ]);

    $response = $this->withCookie(config('session.cookie'), $sessionId)->get(route('login'));

    if ($expired) {
        $response->assertOk()->assertInertia(fn ($page) => $page->component('auth/login'));
        $this->assertGuest();
    } else {
        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
})->with(['valid' => false, 'expired' => true]);

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
