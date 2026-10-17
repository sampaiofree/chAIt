<?php

namespace App\Enums;

enum OnboardingMode: string
{
    case New = 'new';
    case CloudExisting = 'cloud_existing';
    case WhatsAppAppCoexistence = 'wa_app_coexistence';
    case Migration = 'migration';
}
