<?php

namespace App\Enums;

/**
 * One payment towards a bill: payments.payment_status.
 */
enum PaymentStatus: string
{
    case Pending = 'Pending';
    case Paid = 'Paid';
    case Failed = 'Failed';
    // Given back in full. A partly refunded payment stays Paid.
    case Refunded = 'Refunded';
    // Struck out after it was taken (BillingService::voidPayment).
    case Voided = 'Voided';
}
