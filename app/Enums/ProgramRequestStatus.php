<?php

namespace App\Enums;

/**
 * A manager's request for a new voucher, discount or membership for their branch:
 * program_requests.status (ProgramRequestService).
 */
enum ProgramRequestStatus: string
{
    case Pending = 'pending';
    // The program exists (customer_program_id).
    case Approved = 'approved';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}
