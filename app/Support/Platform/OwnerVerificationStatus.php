<?php

namespace App\Support\Platform;

use App\Enums\VerificationStatus;

// Overall account verification status (spec: INCOMPLETE / PENDING_REVIEW /
// VERIFIED / ACTION_REQUIRED) is deliberately computed rather than stored —
// owner_identity_verifications.status and spa_businesses.verification_status
// are the two real sources of truth; a third stored column would risk
// drifting out of sync across independent partial approve/reject actions.
// Used by UserResource and OwnerVerificationService.
class OwnerVerificationStatus
{
    public static function compute(string $identityStatus, string $businessStatus): string
    {
        if ($identityStatus === VerificationStatus::Rejected->value || $businessStatus === VerificationStatus::Rejected->value) {
            return 'ACTION_REQUIRED';
        }

        if ($identityStatus === VerificationStatus::Unregistered->value || $businessStatus === VerificationStatus::Unregistered->value) {
            return 'INCOMPLETE';
        }

        if ($identityStatus === VerificationStatus::Verified->value && $businessStatus === VerificationStatus::Verified->value) {
            return 'VERIFIED';
        }

        return 'PENDING_REVIEW';
    }
}
