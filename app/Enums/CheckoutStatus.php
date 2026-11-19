<?php

namespace App\Enums;

/**
 * One payment attempt at SERVORA's own checkout: checkout_sessions.status.
 */
enum CheckoutStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
