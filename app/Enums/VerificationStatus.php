<?php

namespace App\Enums;

/**
 * Whether a business, a branch or an owner's identity has been checked by an administrator:
 * spa_businesses.verification_status, spa_branches.verification_status and
 * owner_identity_verifications.status (which never uses Suspended).
 */
enum VerificationStatus: string
{
    // Nothing sent in yet.
    case Unregistered = 'Unregistered';
    // Sent in, waiting for an administrator.
    case Pending = 'Pending';
    case Verified = 'Verified';
    case Rejected = 'Rejected';
    case Suspended = 'Suspended';
}
