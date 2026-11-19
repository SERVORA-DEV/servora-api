<?php

namespace App\Policies;

use App\Models\CustomerProgram;
use App\Models\SpaBranch;
use App\Models\User;
use Illuminate\Auth\Access\Response;

// A customer program (points, vouchers, discounts, memberships). Where it may be offered at all is
// SpaBranchPolicy::changePrograms.
class CustomerProgramPolicy
{
    // Offering a program made for one branch at another one: only the owner, and then it is no
    // longer that branch's own. 422 for anyone else: they ask the owner.
    public function offerAtAnotherBranch(User $user, CustomerProgram $program, SpaBranch $branch): Response
    {
        return $user->role === 'business_owner'
            ? Response::allow()
            : Response::denyWithStatus(422, "\"{$program->name}\" was made for another branch. Ask the owner to offer it at {$branch->branch_name}.");
    }
}
