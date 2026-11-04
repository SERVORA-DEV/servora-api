<?php

namespace App\Repository\System;

use App\Models\OwnerIdentityVerification;
use App\Models\SpaBusiness;
use App\Models\User;

class OwnerVerificationRepository
{
    // "Pending Verifications" list — one row per ACCOUNT (business) with
    // either part currently awaiting review, not every account regardless
    // of status.
    public function paginatePending(int $perPage = 15)
    {
        return SpaBusiness::with(['owner.ownerIdentityVerification', 'verifier'])
            ->where(function ($query) {
                $query->where('verification_status', 'Pending')
                    ->orWhereHas('owner.ownerIdentityVerification', function ($identityQuery) {
                        $identityQuery->where('status', 'Pending');
                    });
            })
            ->latest()
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid): SpaBusiness
    {
        return SpaBusiness::with(['owner.ownerIdentityVerification.verifier', 'verifier'])
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function updateIdentity(OwnerIdentityVerification $identity, array $payload): OwnerIdentityVerification
    {
        $identity->update($payload);

        return $identity->fresh();
    }

    // All review writes use the same lock order, including final decisions.
    public function findForReviewUpdate(string $uuid): SpaBusiness
    {
        $business = SpaBusiness::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
        $owner = $business->owner_id ? User::whereKey($business->owner_id)->lockForUpdate()->first() : null;
        if ($owner) {
            $owner->setRelation('ownerIdentityVerification', OwnerIdentityVerification::where('user_id', $owner->id)->lockForUpdate()->first());
        }
        return $business->setRelation('owner', $owner);
    }
}
