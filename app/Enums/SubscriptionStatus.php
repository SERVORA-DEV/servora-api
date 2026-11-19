<?php

namespace App\Enums;

/**
 * A business's SERVORA plan: subscriptions.status.
 */
enum SubscriptionStatus: string
{
    case Pending = 'Pending';
    case Active = 'Active';
    case Expired = 'Expired';
    case Cancelled = 'Cancelled';
}
