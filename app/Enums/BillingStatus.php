<?php

namespace App\Enums;

/**
 * A bill: billings.status. Used for visit, membership and subscription bills alike.
 */
enum BillingStatus: string
{
    // Not fully paid yet (it may have part payments).
    case Pending = 'Pending';
    case Paid = 'Paid';
    case Overdue = 'Overdue';
    case Cancelled = 'Cancelled';
    case Refunded = 'Refunded';
}
