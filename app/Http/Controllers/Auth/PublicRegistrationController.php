<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\AccountEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;

class PublicRegistrationController extends Controller
{
    public function start(Request $request)
    {
        abort_unless(Features::enabled(Features::registration()), 404);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', new AccountEmail], 'password' => ['required', 'string', Password::default(), 'confirmed'], 'terms' => ['accepted']]);
        $this->limitEmail($data['email'], $request->ip());
        $secret = Str::random(64);
        $code = (string) random_int(100000, 999999);
        DB::table('pending_registrations')->insert(['secret' => hash('sha256', $secret), 'name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'code' => Hash::make($code), 'attempts' => 0, 'code_expires_at' => now()->addMinutes(10), 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $request->session()->put('registration_secret', $secret);
        Mail::raw("Seu código de confirmação chAIt é: {$code}. Ele expira em 10 minutos.", fn ($mail) => $mail->to($data['email'])->subject('Confirme seu e-mail no chAIt'));

        return redirect('/register/confirm');
    }

    public function show(Request $request)
    {
        abort_unless(Features::enabled(Features::registration()) && $request->session()->has('registration_secret'), 404);

        return Inertia::render('auth/confirm-registration');
    }

    public function confirm(Request $request)
    {
        abort_unless(Features::enabled(Features::registration()), 404);
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $secret = hash('sha256', (string) $request->session()->get('registration_secret', ''));
        $result = DB::transaction(function () use ($secret, $data) {
            $pending = DB::table('pending_registrations')->where('secret', $secret)->lockForUpdate()->first();
            if (! $pending || $pending->attempts >= 5 || now()->greaterThan($pending->code_expires_at)) {
                return false;
            }
            if (! Hash::check($data['code'], $pending->code)) {
                DB::table('pending_registrations')->where('id', $pending->id)->increment('attempts');

                return false;
            }
            if (User::query()->whereRaw('LOWER(email) = ?', [$pending->email])->exists()) {
                return false;
            }
            $user = User::create(['name' => $pending->name, 'email' => $pending->email, 'password' => $pending->password]);
            $user->forceFill(['email_verified_at' => now(), 'terms_version' => '1'])->save();
            DB::table('pending_registrations')->where('id', $pending->id)->delete();

            return true;
        });
        if (! $result) {
            throw ValidationException::withMessages(['code' => 'Código inválido ou expirado. Solicite outro código ou reinicie o cadastro.']);
        }
        $request->session()->forget('registration_secret');

        return redirect('/login')->with('status', 'E-mail confirmado. Você já pode entrar.');
    }

    private function limitEmail(string $email, ?string $ip): void
    {
        $key = 'registration-email:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Limite de envios atingido. Tente novamente mais tarde.']);
        }
        $ipKey = 'registration-ip:'.hash('sha256', (string) $ip);
        if (RateLimiter::tooManyAttempts($ipKey, 5)) {
            throw ValidationException::withMessages(['email' => 'Limite de envios atingido. Tente novamente mais tarde.']);
        }
        RateLimiter::hit($key, 3600);
        RateLimiter::hit($ipKey, 3600);
    }

    public function resend(Request $request)
    {
        abort_unless(Features::enabled(Features::registration()), 404);
        $pending = DB::table('pending_registrations')->where('secret', hash('sha256', (string) $request->session()->get('registration_secret', '')))->first();
        if (! $pending || now()->diffInSeconds($pending->sent_at) < 60 || now()->diffInHours($pending->created_at) >= 24) {
            throw ValidationException::withMessages(['code' => 'Aguarde para reenviar ou reinicie o cadastro.']);
        }
        $this->limitEmail($pending->email, $request->ip());
        $code = (string) random_int(100000, 999999);
        DB::table('pending_registrations')->where('id', $pending->id)->update(['code' => Hash::make($code), 'attempts' => 0, 'sent_at' => now(), 'code_expires_at' => now()->addMinutes(10)]);
        Mail::raw("Seu código de confirmação chAIt é: {$code}. Ele expira em 10 minutos.", fn ($mail) => $mail->to($pending->email)->subject('Confirme seu e-mail no chAIt'));

        return back()->with('status', 'Enviamos outro código.');
    }
}
