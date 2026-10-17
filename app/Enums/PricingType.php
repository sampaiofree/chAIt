<?php

namespace App\Enums;

enum PricingType: string
{
    case Regular = 'regular';
    case FreeCustomerService = 'free_customer_service';
    case FreeEntryPoint = 'free_entry_point';
}
