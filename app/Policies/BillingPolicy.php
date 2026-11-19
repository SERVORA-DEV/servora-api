<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

// A visit's bill, beyond the route's permission (BillingService).
class BillingPolicy
{
    // Putting a client's voucher on a bill. The front desk uses only the discounts the owner made;
    // a voucher is the client's, used from their app. 422 for the Front Officer.
    public function useClientVoucher(User $user): Response
    {
        return $user->role === 'front_officer'
            ? Response::denyWithStatus(422, 'Vouchers are used in the client\'s mobile app, not by the Front Officer.')
            : Response::allow();
    }
}
