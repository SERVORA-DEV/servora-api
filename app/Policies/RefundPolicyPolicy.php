<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

// The owner's refund policies (RefundPolicyService). ("Policy" twice: the model is the spa's
// refund policy, this class is who may change it.)
class RefundPolicyPolicy
{
    // Adding, changing and removing them, and "refunds outside these policies".
    public function manage(User $user): Response
    {
        return $user->role === 'business_owner'
            ? Response::allow()
            : Response::deny('Only the owner sets the refund policies.');
    }
}
