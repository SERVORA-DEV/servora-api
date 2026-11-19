<?php

namespace App\Enums;

/**
 * Whether a business or branch is trading: spa_businesses.operating_status and
 * spa_branches.operating_status (only a branch can be Temporarily closed).
 */
enum OperatingStatus: string
{
    case Active = 'Active';
    case Inactive = 'Inactive';
    case TemporarilyClosed = 'Temporarily Closed';
}
