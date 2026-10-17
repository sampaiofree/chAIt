<?php

namespace App\Services\ExternalWebhooks;

use RuntimeException;

class WebhookFailure extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
