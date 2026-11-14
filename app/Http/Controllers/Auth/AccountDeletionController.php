<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AccountDeletionController extends Controller
{
    public static function block(User $user, string $origin): void
    {
        if ($user->isSystemAdmin()) {
            throw ValidationException::withMessages(['email' => 'A conta administrativa deve ser tratada pelo suporte.']);
        }
        DB::transaction(function () use ($user, $origin) {
            DB::table('account_deletion_requests')->insertOrIgnore(['user_id' => $user->id, 'origin' => $origin, 'created_at' => now(), 'updated_at' => now()]);
            $user->forceFill(['access_blocked_at' => $user->access_blocked_at ?? now(), 'remember_token' => null])->save();
        });
    }

    public function show()
    {
        return Inertia::render('auth/delete-account');
    }

    public function start(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = Str::lower(trim($data['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        $secret = Str::random(64);
        $code = (string) random_int(100000, 999999);
        Cache::put('deletion:'.hash('sha256', $secret), ['user_id' => $user && ! $user->isSystemAdmin() ? $user->id : null, 'code' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)->timestamp], now()->addMinutes(10));
        $request->session()->put('deletion_secret', $secret);
        if ($user && ! $user->isSystemAdmin()) {
            Mail::raw("Seu código para solicitar a exclusão da conta chAIt é: {$code}. Ele expira em 10 minutos.", fn ($mail) => $mail->to($email)->subject('Solicitação de exclusão de conta'));
        }

        return back()->with('status', 'Se houver uma conta elegível, enviaremos um código para o e-mail informado.');
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $key = 'deletion:'.hash('sha256', (string) $request->session()->get('deletion_secret', ''));
        $challenge = Cache::get($key);
        if (! $challenge || $challenge['attempts'] >= 5 || now()->timestamp >= $challenge['expires_at'] || ! Hash::check($data['code'], $challenge['code']) || ! $challenge['user_id']) {
            if ($challenge) {
                $challenge['attempts']++;
                Cache::put($key, $challenge, Carbon::createFromTimestamp($challenge['expires_at']));
            }
            throw ValidationException::withMessages(['code' => 'Código inválido ou expirado.']);
        }
        $user = User::find($challenge['user_id']);
        if ($user) {
            self::block($user, 'web');
        }
        Cache::forget($key);
        $request->session()->forget('deletion_secret');

        return redirect('/login')->with('status', 'Solicitação registrada e acesso bloqueado.');
    }
}
