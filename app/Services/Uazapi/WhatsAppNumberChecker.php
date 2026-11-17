<?php

namespace App\Services\Uazapi;

use App\Enums\WhatsAppNumberStatus;
use App\Models\UazapiConnection;
use App\Services\Contacts\InternationalPhone;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppNumberChecker
{
    public function __construct(private UazapiClient $client, private InternationalPhone $phones) {}

    /** The caller must authorize the connection and supply a normalized international phone. */
    public function check(UazapiConnection $connection, string $phone): WhatsAppNumberCheckResult
    {
        if ($this->phones->normalize($phone) !== $phone) {
            throw new InvalidArgumentException('Expected a normalized international phone number.');
        }
        if (! filled($connection->instance_token)) {
            return new WhatsAppNumberCheckResult(WhatsAppNumberStatus::Unavailable);
        }
        try {
            $data = $this->client->checkNumbers($connection, [$phone]);
        } catch (UazapiDeferredException $error) {
            return new WhatsAppNumberCheckResult(WhatsAppNumberStatus::Unavailable, retryAfter: $error->delay);
        } catch (RuntimeException) {
            return new WhatsAppNumberCheckResult(WhatsAppNumberStatus::Unavailable);
        }

        if (! array_is_list($data)) {
            return new WhatsAppNumberCheckResult(WhatsAppNumberStatus::Unavailable);
        }
        $matches = array_values(array_filter($data, fn ($item) => is_array($item) && ($item['query'] ?? null) === $phone));
        if (count($matches) !== 1) {
            return new WhatsAppNumberCheckResult(WhatsAppNumberStatus::Unavailable);
        }
        $item = $matches[0];
        if (! is_bool($item['isInWhatsapp'] ?? null) || ! in_array($item['error'] ?? null, [null, ''], true)) {
            return new WhatsAppNumberCheckResult(WhatsAppNumberStatus::Unavailable);
        }

        return new WhatsAppNumberCheckResult(
            $item['isInWhatsapp'] ? WhatsAppNumberStatus::Registered : WhatsAppNumberStatus::NotRegistered,
            jid: is_string($item['jid'] ?? null) ? $item['jid'] : null,
            verifiedName: is_string($item['verifiedName'] ?? null) ? $item['verifiedName'] : null,
        );
    }
}
