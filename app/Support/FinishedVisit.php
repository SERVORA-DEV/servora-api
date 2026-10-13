<?php

namespace App\Support;

// A visit that is finished — every service done and the bill paid — is closed
// for good: none of its services can be voided, and none of its payments can
// be voided or refunded. (A visit only becomes 'completed' when its bill is
// paid in full: BillingService::settle.)
//
// A cancelled booking is not a finished visit — what was paid on it can still
// be handed back under the cancellation policy.
//
// Pure — tested in tests/Unit/FinishedVisitTest. Applied by
// BillingService::voidPayment / refund and by ServiceVoidRules; BillingResource
// passes it to the screens as `locked` so the buttons are not offered.
class FinishedVisit
{
    public const LOCKED = 'This visit is finished and paid — it can no longer be voided or refunded.';

    public static function isLocked(?string $appointmentStatus): bool
    {
        return $appointmentStatus === 'completed';
    }

    /** The reason to give when something tries to undo it, or nothing. */
    public static function problem(?string $appointmentStatus): ?string
    {
        return self::isLocked($appointmentStatus) ? self::LOCKED : null;
    }
}
