<?php

namespace App\Repository\System;

use App\Models\SpaBusiness;

class BusinessRepository
{
    // All registered businesses regardless of status — unlike branches,
    // there's no separate "pending business registrations" review page in
    // this app, so this list covers Pending/Verified/Suspended/Rejected all
    // at once (the status filter on the frontend narrows it from there).
    public function paginateAll(int $perPage = 100)
    {
        return SpaBusiness::with(['owner', 'activeSubscription.plan'])
            ->withCount('branches')
            ->latest()
            ->paginate($perPage);
    }

    public function countByVerificationStatus(string $status): int
    {
        return SpaBusiness::where('verification_status', $status)->count();
    }

    public function findByUuid(string $uuid): SpaBusiness
    {
        // The admin detail page is a legitimacy review: registration and
        // owner identity documents, plus every branch's permit, location and
        // the services it offers. Billing and the catalog live elsewhere.
        return SpaBusiness::with([
            'owner.ownerIdentityVerification.verifier',
            'verifier',
            'branches.branchServices.serviceVariant.service',
            'activeSubscription.plan',
        ])->where('uuid', $uuid)->firstOrFail();
    }

    // Same eager-loads as paginateAll(), scoped to one row — used to
    // re-fetch a business in the LIST shape (not findByUuid's detail shape)
    // after an action like suspend/reactivate, so BusinessResource has
    // everything it needs (owner, activeSubscription.plan, branches_count).
    public function findForList(string $uuid): SpaBusiness
    {
        return SpaBusiness::with(['owner', 'activeSubscription.plan'])
            ->withCount('branches')
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function update(SpaBusiness $business, array $payload): SpaBusiness
    {
        $business->update($payload);

        return $business->fresh();
    }
}
