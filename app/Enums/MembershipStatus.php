<?php

namespace App\Enums;

/**
 * A client's membership: client_memberships.status.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
