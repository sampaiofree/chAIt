<?php

namespace App\Services\Uazapi;

use App\Enums\WhatsAppNumberStatus;

final readonly class WhatsAppNumberCheckResult
{
    public function __construct(
        public WhatsAppNumberStatus $status,
        public ?string $jid = null,
        public ?string $verifiedName = null,
        public ?int $retryAfter = null,
    ) {}
}
