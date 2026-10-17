<?php

namespace App\Services\Contacts;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class InternationalPhone
{
    public function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        // Do not extract digits from JIDs, extensions, words or decimal numbers.
        if (! preg_match('/^\+?[0-9 ()\-]+$/D', $value)) {
            return null;
        }
        $digits = preg_replace('/[ ()\-]/', '', $value);
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } elseif (! str_starts_with($digits, '+')) {
            $digits = '+'.$digits;
        }
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($digits, null);
            if ($number->hasExtension() || ! $util->isValidNumber($number)) {
                return null;
            }

            return substr($util->format($number, PhoneNumberFormat::E164), 1);
        } catch (NumberParseException) {
            return null;
        }
    }
}
