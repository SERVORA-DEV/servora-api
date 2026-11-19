<?php

namespace App\Enums;

/**
 * A change to a bill after it was paid (a voucher afterwards, a refund):
 * billing_adjustments.status (BillingAdjustmentService).
 */
enum BillingAdjustmentStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}
