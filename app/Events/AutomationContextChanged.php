<?php

namespace App\Events;

use App\Models\AutomationContext;

/** Synchronous notification inside the contact transaction; state remains durable in SQL. */
class AutomationContextChanged
{
    public function __construct(public AutomationContext $context) {}
}
