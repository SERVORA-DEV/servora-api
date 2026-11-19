<?php

namespace App\Enums;

/**
 * An employee: staff.status.
 */
enum StaffStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case OnLeave = 'on-leave';
    case Terminated = 'terminated';
}
