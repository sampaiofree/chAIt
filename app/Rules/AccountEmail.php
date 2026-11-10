<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AccountEmail implements ValidationRule
{
    public function __construct(private readonly ?int $userId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $email = strtolower(trim($value));
        $current = $this->userId ? User::query()->find($this->userId) : null;

        if (($email === User::SYSTEM_ADMIN_EMAIL && ! $current?->isSystemAdmin()) || ($current?->isSystemAdmin() && $email !== User::SYSTEM_ADMIN_EMAIL)) {
            $fail('Este endereço de e-mail é reservado à administração.');

            return;
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->when($this->userId, fn ($query) => $query->whereKeyNot($this->userId))->exists()) {
            $fail('Este e-mail já está cadastrado.');
        }
    }
}
