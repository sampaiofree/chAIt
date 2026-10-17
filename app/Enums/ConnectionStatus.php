<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Draft = 'draft';
    case Authorizing = 'authorizing';
    case Connected = 'connected';
    case NeedsPayment = 'needs_payment';
    case NeedsReview = 'needs_review';
    case Migrating = 'migrating';
    case Degraded = 'degraded';
    case Disconnected = 'disconnected';
    case Error = 'error';
}
