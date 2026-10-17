<?php

namespace App\Services\Uazapi;

/** No mutation was accepted: reschedule the job instead of sleeping in a worker. */
class UazapiDeferredException extends \RuntimeException
{
    public function __construct(public int $delay = 1, public string $reason = 'capacity')
    {
        parent::__construct('uazapi_deferred');
    }
}
