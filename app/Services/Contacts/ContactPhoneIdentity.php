<?php

namespace App\Services\Contacts;

use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Throwable;

class ContactPhoneIdentity
{
    public function variants(string $phone): array
    {
        if (! preg_match('/^55[1-9][0-9](?:9[0-9]{8}|[0-9]{8})$/D', $phone)) {
            return [$phone];
        }
        $long = strlen($phone) === 12 ? substr($phone, 0, 4).'9'.substr($phone, 4) : $phone;
        $short = substr($long, 0, 4).substr($long, 5);
        try {
            $util = PhoneNumberUtil::getInstance();
            $mobile = $util->parse('+'.$long, null);
            $old = $util->parse('+'.$short, null);
            if (! $util->isValidNumber($mobile) || $util->getNumberType($mobile) !== PhoneNumberType::MOBILE || $util->isValidNumber($old)) {
                return [$phone];
            }
        } catch (Throwable) {
            return [$phone];
        }

        return [$long, $short];
    }

    public function normalize(mixed $value): ?string
    {
        $normalized = app(InternationalPhone::class)->normalize($value);
        if ($normalized) {
            return $normalized;
        }
        if (! is_string($value) || ! preg_match('/^\+?[0-9 ()-]+$/D', trim($value))) {
            return null;
        }
        $digits = preg_replace('/\D/', '', trim($value));
        $variants = $this->variants($digits);

        return count($variants) === 2 ? $variants[0] : null;
    }
}
