<?php

namespace App\Policies;

use App\Models\AccountBranch;
use App\Models\Staff;
use App\Models\User;
use App\Support\AppCache;
use Illuminate\Auth\Access\Response;

// What an account may do to a staff member's record beyond the route's permission.
class StaffPolicy
{
    // Giving staff a commission plan: the owner always; a manager when `commission_update` is in
    // their role's bundle and not switched off on their own account (EnsurePermission's rule: the
    // role is the ceiling, the account's own flag can only narrow it).
    public function changeCommission(User $user): bool
    {
        if ($user->role === 'business_owner') {
            return true;
        }
        if (! in_array('commission_update', config('permission.' . $user->role, []), true)) {
            return false;
        }

        return AppCache::permission($user->id)?->commission_update !== false;
    }

    // Moving someone to another branch. A manager can't move another manager, and (before logins
    // had branches of their own, when a login moved with its employee) can't move themselves.
    // 422: the move is refused, the manager can ask the owner.
    public function moveToAnotherBranch(User $user, Staff $staff): Response
    {
        if ($user->role === 'manager') {
            if (! AccountBranch::ready() && $staff->user_id === $user->id) {
                return Response::denyWithStatus(422, "You can't move yourself to another branch. Ask the spa owner.");
            }
            if ($staff->role === 'manager') {
                return Response::denyWithStatus(422, 'Only the spa owner can move a manager to another branch.');
            }
        }

        return Response::allow();
    }
}
