<?php

namespace App\Policies;

use App\Enums\BillingAdjustmentStatus;
use App\Models\BillingAdjustment;
use App\Models\User;
use App\Support\Access\AccountScope;
use App\Support\AppCache;
use App\Support\Billing\BillAdjustment;

// Changing a bill after it is paid (BillingAdjustmentService). Who must agree to which change is
// App\Support\Billing\BillAdjustment; this says what this account is allowed.
class BillingAdjustmentPolicy
{
    // "Adjust paid bills" (payment_adjust): the change applies at once, without asking anyone.
    // The owner always; another account when its role's bundle has the permission and its own
    // flag doesn't turn it off (before the flag has a column, it is the manager's).
    public function adjustAtOnce(User $user): bool
    {
        if ($user->role === 'business_owner') {
            return true;
        }
        if (! BillingAdjustment::ready() || ! in_array('payment_adjust', config("permission.{$user->role}", []), true)) {
            return false;
        }
        $row = $user->relationLoaded('permission') ? $user->permission : AppCache::permission($user->id);

        return $row ? $row->getAttribute('payment_adjust') === true : $user->role === 'manager';
    }

    // Approving or declining a request that is still waiting.
    public function decide(User $user, BillingAdjustment $adjustment): bool
    {
        return $adjustment->status === BillingAdjustmentStatus::Pending->value && BillAdjustment::mayDecide(
            $user->role,
            $this->adjustAtOnce($user),
            (string) $adjustment->needs,
            $user->role === 'manager' && $this->managesBranch($user, $adjustment->spa_branch_id),
            $adjustment->requested_by_user_id === $user->id,
        );
    }

    private function managesBranch(User $user, ?int $branchId): bool
    {
        return $branchId !== null && in_array($user->id, AccountScope::userIdsAt([$branchId], ['manager']), true);
    }
}
