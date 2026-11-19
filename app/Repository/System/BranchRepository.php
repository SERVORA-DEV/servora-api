<?php

namespace App\Repository\System;

use App\Enums\OperatingStatus;
use App\Enums\VerificationStatus;
use App\Models\SpaBranch;

class BranchRepository
{
    // "Branches" list — branches that have completed the registration flow
    // (approved or since suspended), not the ones still awaiting review
    // (those live on /system/pending, scoped to 'Pending' by
    // BranchRegistrationRepository::paginatePending) or ones never
    // submitted/rejected.
    /**
     * One page of the verified and suspended branches, filtered by the API (the admin Branches
     * page): search (the branch's name or code, its manager's name or email), business (its
     * name), status (active, disabled), operating (Active, Inactive, Temporarily Closed, unknown).
     */
    public function paginateAll(int $perPage = 100, array $filters = [])
    {
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
        $like = '%' . mb_strtolower($search) . '%';

        return SpaBranch::with(['business.owner', 'manager'])
            ->whereIn('verification_status', [VerificationStatus::Verified->value, VerificationStatus::Suspended->value])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('branch_name', "%{$search}%")
                ->orWhereLike('code', "%{$search}%")
                ->orWhereHas('manager', fn ($m) => $m->whereRaw("LOWER(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')) LIKE ?", [$like])
                    ->orWhereLike('email', "%{$search}%"))))
            ->when($filters['business'] ?? null, fn ($q, $name) => $q->whereHas('business', fn ($b) => $b->where('business_name', $name)))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('verification_status', VerificationStatus::Verified->value))
            ->when(($filters['status'] ?? null) === 'disabled', fn ($q) => $q->where('verification_status', VerificationStatus::Suspended->value))
            // Active, Inactive, Temporarily Closed, or "unknown" (none of them: not specified).
            ->when($filters['operating'] ?? null, fn ($q, $o) => $o === 'unknown'
                ? $q->where(fn ($w) => $w->whereNull('operating_status')->orWhereNotIn('operating_status', array_column(OperatingStatus::cases(), 'value')))
                : $q->where('operating_status', $o))
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** The listed branches per operating status (Active, Inactive, Temporarily Closed, unknown). */
    public function operatingCounts(): array
    {
        $known = array_column(OperatingStatus::cases(), 'value');
        $rows = SpaBranch::whereIn('verification_status', [VerificationStatus::Verified->value, VerificationStatus::Suspended->value])
            ->groupBy('operating_status')->selectRaw('operating_status as s, count(*) as n')->pluck('n', 's');
        $out = array_fill_keys([...$known, 'unknown'], 0);
        foreach ($rows as $s => $n) {
            $out[in_array($s, $known, true) ? $s : 'unknown'] += (int) $n;
        }

        return $out;
    }

    /** The businesses a branch can be filtered by (those with a listed branch). */
    public function businessOptions(): array
    {
        return \App\Models\SpaBusiness::whereHas('branches', fn ($b) => $b->whereIn('verification_status', [VerificationStatus::Verified->value, VerificationStatus::Suspended->value]))
            ->orderBy('business_name')->pluck('business_name')->filter()->unique()->values()->all();
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
