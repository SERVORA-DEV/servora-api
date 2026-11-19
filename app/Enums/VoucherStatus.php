<?php

namespace App\Enums;

/**
 * A voucher in a client's wallet: client_vouchers.status.
 */
enum VoucherStatus: string
{
    case Available = 'available';
    case Used = 'used';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
