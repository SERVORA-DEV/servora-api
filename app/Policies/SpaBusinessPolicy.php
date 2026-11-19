<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

// The business itself.
class SpaBusinessPolicy
{
    // Asking to change the owner's verified details: the registration (BusinessRegistrationChangeService)
    // or their own name and email (OwnerIdentityChangeService). Only the owner.
    public function changeOwnerDetails(User $user): Response
    {
        return $user->role === 'business_owner'
            ? Response::allow()
            : Response::deny('Only the owner can do this.');
    }
}
