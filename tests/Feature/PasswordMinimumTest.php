<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

function passwordInput(string $password): array
{
    return ['password' => $password, 'password_confirmation' => $password];
}

test('administrative creation and editing enforce the password minimum', function (string $password, bool $valid) {
    $admin = User::factory()->create(['email' => User::SYSTEM_ADMIN_EMAIL]);
    $target = User::factory()->create(['phone' => '5511999999999']);
    $data = ['name' => 'Pessoa', 'email' => 'pessoa@example.com', 'phone' => '5511999999999', ...passwordInput($password)];

    $response = $this->actingAs($admin)->post('/admin/usuarios', $data);
    $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('password');
    $data['email'] = $target->email;
    $response = $this->patch("/admin/usuarios/{$target->id}", $data);
    $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('password');
    expect(Hash::check($password, $target->fresh()->password))->toBe($valid);
})->with(['five characters' => ['abcde', false], 'six characters' => ['abcdef', true]]);

test('team creation enforces the password minimum', function (string $password, bool $valid) {
    $owner = User::factory()->create();
    $response = $this->actingAs($owner)->post("/workspaces/{$owner->current_workspace_id}/members", [
        'name' => 'Membro', 'email' => 'membro@example.com', 'phone' => '5511999999999',
        'can_view_unassigned' => false, 'can_manage_flows' => false, ...passwordInput($password),
    ]);
    $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('password');
    expect(User::where('email', 'membro@example.com')->exists())->toBe($valid);
})->with(['five characters' => ['abcde', false], 'six characters' => ['abcdef', true]]);

test('public registration and password reset enforce the password minimum', function (string $password, bool $valid) {
    $data = ['name' => 'Pessoa', 'email' => 'pessoa@example.com', ...passwordInput($password)];
    $existing = User::factory()->create(['password' => 'old-password']);

    if ($valid) {
        $created = app(CreateNewUser::class)->create($data);
        app(ResetUserPassword::class)->reset($existing, passwordInput($password));
        expect(Hash::check($password, $created->password))->toBeTrue()
            ->and(Hash::check($password, $existing->fresh()->password))->toBeTrue();
    } else {
        expect(fn () => app(CreateNewUser::class)->create($data))->toThrow(ValidationException::class);
        expect(fn () => app(ResetUserPassword::class)->reset($existing, passwordInput($password)))->toThrow(ValidationException::class);
        expect(Hash::check('old-password', $existing->fresh()->password))->toBeTrue();
    }
})->with(['five characters' => ['abcde', false], 'six characters' => ['abcdef', true]]);

test('password change enforces the password minimum', function (string $password, bool $valid) {
    $user = User::factory()->create(['password' => 'old-password']);
    $response = $this->actingAs($user)->put('/settings/password', ['current_password' => 'old-password', ...passwordInput($password)]);
    $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('password');
    expect(Hash::check($password, $user->fresh()->password))->toBe($valid);
})->with(['five characters' => ['abcde', false], 'six characters' => ['abcdef', true]]);

test('production requires six characters, complexity and an uncompromised password', function () {
    $originalEnvironment = $this->app['env'];
    $this->app['env'] = 'production';
    $verifier = Mockery::mock(UncompromisedVerifier::class);
    $verifier->shouldReceive('verify')->andReturnUsing(fn (array $data) => $data['value'] !== 'Bad1!x');
    $this->app->instance(UncompromisedVerifier::class, $verifier);

    try {
        $validate = fn (string $password) => Validator::make(['password' => $password], ['password' => [Password::default()]])->passes();
        expect($validate('Aa1!x'))->toBeFalse()
            ->and($validate('Abc1!x'))->toBeTrue()
            ->and($validate('abc1!x'))->toBeFalse()
            ->and($validate('ABC1!X'))->toBeFalse()
            ->and($validate('Abcd!x'))->toBeFalse()
            ->and($validate('Abcd1x'))->toBeFalse()
            ->and($validate('Bad1!x'))->toBeFalse();
    } finally {
        $this->app['env'] = $originalEnvironment;
    }
});
