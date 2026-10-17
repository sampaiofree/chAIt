<?php

namespace App\Enums;

enum ThreadAssignmentState: string
{
    case Active = 'active';
    case Released = 'released';
    case Snoozed = 'snoozed';
}
