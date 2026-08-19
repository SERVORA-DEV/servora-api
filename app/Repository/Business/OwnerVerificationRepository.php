<?php

namespace App\Repository\Business;

use App\Models\OwnerIdentityVerification;
use App\Models\User;

class OwnerVerificationRepository
{
    // Every business_owner gets exactly one identity verification row,
    // created lazily on first access rather than at registration time (a
    // fresh account has nothing to verify yet).
    public function findOrCreateIdentity(User $user): OwnerIdentityVerification
    {
        // Eloquent's create() doesn't reflect DB column defaults back onto
        // the in-memory instance, so the default status/liveness_result are
        // passed explicitly here rather than relying on the migration's
        // ->default(...) alone.
        return OwnerIdentityVerification::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'Unregistered', 'liveness_result' => 'Pending']
        );
    }

    public function updateIdentity(User $user, array $payload): OwnerIdentityVerification
    {
        $identity = $this->findOrCreateIdentity($user);
        $identity->update($payload);

        return $identity->fresh();
    }
}
