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
}
