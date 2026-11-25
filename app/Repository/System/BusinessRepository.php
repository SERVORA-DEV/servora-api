<?php

namespace App\Repository\System;

use App\Enums\VerificationStatus;
use App\Models\SpaBusiness;

class BusinessRepository
{
    // All registered businesses regardless of status — unlike branches,
    // there's no separate "pending business registrations" review page in
    // this app, so this list covers Pending/Verified/Suspended/Rejected all
    // at once (the status filter on the frontend narrows it from there).
    /**
     * One page of businesses, filtered by the API (the admin Spa Businesses page): search (the
     * business or its owner's name), plan (the current plan's name, or "No Plan"), status
     * (active, pending, suspended, rejected, unverified), sort (newest, oldest, name).
     */
    public function paginateAll(int $perPage = 100, array $filters = [])
    {
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
        $statuses = [
            'active' => VerificationStatus::Verified->value, 'pending' => VerificationStatus::Pending->value,
            'suspended' => VerificationStatus::Suspended->value, 'rejected' => VerificationStatus::Rejected->value,
        ];
        $status = $filters['status'] ?? null;

        return SpaBusiness::with(['owner', 'activeSubscription.plan'])
            ->withCount('branches')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('business_name', "%{$search}%")
                ->orWhereHas('owner', fn ($o) => $o->whereRaw("LOWER(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')) LIKE ?", ['%' . mb_strtolower($search) . '%'])
                    ->orWhereLike('email', "%{$search}%"))))
            ->when($filters['plan'] ?? null, fn ($q, $plan) => $plan === 'No Plan'
                ? $q->whereDoesntHave('activeSubscription.plan')
                : $q->whereHas('activeSubscription.plan', fn ($p) => $p->where('name', $plan)))
            ->when($status, fn ($q) => isset($statuses[$status])
                ? $q->where('verification_status', $statuses[$status])
                // "unverified": anything else (not sent in yet).
                : $q->whereNotIn('verification_status', array_values($statuses)))
            ->when(($filters['sort'] ?? null) === 'name', fn ($q) => $q->orderBy('business_name'))
            ->when(($filters['sort'] ?? null) === 'oldest', fn ($q) => $q->oldest()->orderBy('id'), fn ($q) => $q->when(($filters['sort'] ?? null) !== 'name', fn ($x) => $x->latest()->orderByDesc('id')))
            ->paginate($perPage);
    }

    /** The plans a business can be filtered by: every plan's name, and "No Plan". */
    public function planOptions(): array
    {
        return [...\App\Models\SubscriptionPlan::orderBy('name')->pluck('name')->unique()->values()->all(), 'No Plan'];
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

}
