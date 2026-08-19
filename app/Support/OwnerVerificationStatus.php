<?php

namespace App\Support;

// Overall account verification status (spec: INCOMPLETE / PENDING_REVIEW /
// VERIFIED / ACTION_REQUIRED) is deliberately computed rather than stored —
// owner_identity_verifications.status and spa_businesses.verification_status
// are the two real sources of truth; a third stored column would risk
// drifting out of sync across independent partial approve/reject actions.
// Used by EnsureBusinessVerified, UserResource, and OwnerVerificationService.
class OwnerVerificationStatus
{
    public static function compute(string $identityStatus, string $businessStatus): string
    {
        if ($identityStatus === 'Rejected' || $businessStatus === 'Rejected') {
            return 'ACTION_REQUIRED';
        }

        if ($identityStatus === 'Unregistered' || $businessStatus === 'Unregistered') {
            return 'INCOMPLETE';
        }

        if ($identityStatus === 'Verified' && $businessStatus === 'Verified') {
            return 'VERIFIED';
        }

        return 'PENDING_REVIEW';
    }
}
