<?php

namespace App\Enums;

/**
 * A manager's request to move a staff member to another branch:
 * staff_transfer_requests.status (StaffTransferService).
 */
enum StaffTransferStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    // Replaced by a newer request, or overtaken by the owner's own move.
    case Superseded = 'superseded';
}
