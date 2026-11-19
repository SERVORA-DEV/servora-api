<?php

namespace App\Enums;

/**
 * An owner's request to change a verified identity or company registration:
 * owner_identity_changes.status and business_registration_changes.status (which starts at Pending).
 */
enum ChangeRequestStatus: string
{
    // Being filled in; not sent yet.
    case Draft = 'Draft';
    // Sent, waiting for an administrator.
    case Pending = 'Pending';
    case Approved = 'Approved';
    case Rejected = 'Rejected';
    // Withdrawn by the owner.
    case Cancelled = 'Cancelled';
}
