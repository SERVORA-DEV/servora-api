<?php

namespace App\Repository\System;

use App\Models\SpaBranch;

class BranchRepository
{
    // "Branches" list — branches that have completed the registration flow
    // (approved or since suspended), not the ones still awaiting review
    // (those live on /system/pending, scoped to 'Pending' by
    // BranchRegistrationRepository::paginatePending) or ones never
    // submitted/rejected.
    public function paginateAll(int $perPage = 100)
    {
        return SpaBranch::with(['business.owner', 'manager'])
            ->whereIn('verification_status', ['Verified', 'Suspended'])
            ->latest()
            ->paginate($perPage);
    }

    public function countByVerificationStatus(string $status): int
    {
        return SpaBranch::where('verification_status', $status)->count();
    }
}
