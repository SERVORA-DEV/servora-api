<?php

namespace App\Policies;

use App\Models\User;
use App\Service\Business\PolicyGate;
use App\Support\AppCache;

// Going ahead at the front desk although one of the owner's or manager's booking rules says no
// (the rules themselves are PolicyGate and App\Support\Visits\FrontDeskPolicy). Not tied to one record,
// so it is the ability "override-booking-rules" (AppServiceProvider).
class BookingRulesPolicy
{
    // The owner always; another account when `policy_override` is in its role's bundle and its own
    // flag doesn't turn it off. Before the permission has a column to live in, it is the manager's.
    public function override(User $user): bool
    {
        if ($user->role === 'business_owner') {
            return true;
        }
        if (! in_array('policy_override', config("permission.{$user->role}", []), true)) {
            return false;
        }
        $byDefault = $user->role === 'manager';
        if (! PolicyGate::ready()) {
            return $byDefault;
        }
        $row = $user->relationLoaded('permission') ? $user->permission : AppCache::permission($user->id);

        return $row ? $row->getAttribute('policy_override') === true : $byDefault;
    }
}
