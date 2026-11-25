<?php

namespace App\Repository\System;

use App\Enums\VerificationStatus;
use App\Models\SpaBranch;

class BranchRegistrationRepository
{
    // "Pending Registrations" list — only branches currently awaiting
    // review, not every branch regardless of status.
    public function paginatePending(int $perPage = 15)
    {
        return SpaBranch::with('business.owner.ownerIdentityVerification')
            ->where('verification_status', VerificationStatus::Pending->value)
            ->latest()
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid, bool $lock = false): SpaBranch
    {
        return SpaBranch::with('business.owner.ownerIdentityVerification', 'schedules', 'verifier')
            ->where('uuid', $uuid)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->firstOrFail();
    }
}
