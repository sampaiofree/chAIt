<?php

namespace App\Enums;

enum MessagingWindowState: string
{
    case FreeEntryPoint = 'free_entry_point';
    case CustomerService = 'customer_service';
    case TemplateOnly = 'template_only';
}
