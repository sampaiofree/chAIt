<?php

namespace App\Enums;

enum WhatsAppNumberStatus: string
{
    case Registered = 'registered';
    case NotRegistered = 'not_registered';
    case Unavailable = 'unavailable';
}
