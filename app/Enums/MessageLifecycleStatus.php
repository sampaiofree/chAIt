<?php

namespace App\Enums;

enum MessageLifecycleStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Inbound = 'inbound';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Played = 'played';
    case Failed = 'failed';
}
