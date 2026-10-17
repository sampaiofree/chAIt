<?php

namespace App\Services\Chat;

use App\Services\Contacts\InternationalPhone;

class WhatsAppJid
{
    public function isIndividual(mixed $jid): bool
    {
        // A phone-number JID is an address supplied by WhatsApp, not proof that
        // its digits are a currently dialable number. Keep the address intact.
        return is_string($jid) && preg_match('/^[1-9][0-9]{7,14}@s\.whatsapp\.net$/D', $jid) === 1;
    }

    public function phone(string $jid): ?string
    {
        if (! $this->isIndividual($jid)) {
            return null;
        }

        return app(InternationalPhone::class)->normalize(strstr($jid, '@', true));
    }
}
