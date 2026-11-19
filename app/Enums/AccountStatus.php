<?php

namespace App\Enums;

/**
 * A login: users.account_status.
 */
enum AccountStatus: string
{
    case Pending = 'Pending';
    case Active = 'Active';
    case Inactive = 'Inactive';
    case Suspended = 'Suspended';
}
