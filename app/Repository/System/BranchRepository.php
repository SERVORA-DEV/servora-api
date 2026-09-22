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

    public function findByUuid(string $uuid): SpaBranch
    {
        return SpaBranch::with([
            'business.owner',
            'verifier',
            'manager',
            'assignedAccount',
            'schedules',
            'staff',
            'facilities',
            'branchServices.serviceVariant.service',
            'branchPackages.package',
        ])->where('uuid', $uuid)->firstOrFail();
    }

    // Same eager-loads as paginateAll(), scoped to one row — used to
    // re-fetch a branch in the LIST shape after suspend/reactivate, so
    // SpaBranchResource's business/manager whenLoaded blocks resolve.
    public function findForList(string $uuid): SpaBranch
    {
        return SpaBranch::with(['business.owner', 'manager'])
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function update(SpaBranch $branch, array $payload): SpaBranch
    {
        $branch->update($payload);

        return $branch->fresh();
    }
}
