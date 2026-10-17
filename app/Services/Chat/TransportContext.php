<?php

namespace App\Services\Chat;

class TransportContext
{
    public bool $direct = false;

    public ?int $executionId = null;
}
