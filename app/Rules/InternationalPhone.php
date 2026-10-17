<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class InternationalPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = 'Informe um telefone internacional válido com DDI, somente dígitos. Ex.: 5511999999999.';

        if (! is_string($value) || ! preg_match('/\A[1-9][0-9]{1,14}\z/', $value)) {
            $fail($message);

            return;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse('+'.$value, null);

            if (! $util->isValidNumber($number) || $util->format($number, PhoneNumberFormat::E164) !== '+'.$value) {
                $fail($message);
            }
        } catch (NumberParseException) {
            $fail($message);
        }
    }
}
