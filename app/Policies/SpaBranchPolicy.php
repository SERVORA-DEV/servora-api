<?php

namespace App\Policies;

use App\Models\SpaBranch;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use Illuminate\Auth\Access\Response;

// Things done at a branch that only the people of that branch (and the owner, who has them all)
// may do there. Which branches are a person's is SpaBusinessRepository::branchIdsForUser.
class SpaBranchPolicy
{
    public function __construct(private SpaBusinessRepository $businesses)
    {
    }

    // Offering a customer program at the branch, or taking it off.
    public function changePrograms(User $user, SpaBranch $branch): Response
    {
        return $this->ownBranch($user, $branch, 'You can only change programs for your own branch.');
    }

    public function sellMemberships(User $user, SpaBranch $branch): Response
    {
        return $this->ownBranch($user, $branch, 'You can only sell memberships at your own branch.');
    }

    private function ownBranch(User $user, SpaBranch $branch, string $message): Response
    {
        return in_array($branch->id, $this->businesses->branchIdsForUser($user), true)
            ? Response::allow()
            : Response::deny($message);
    }
}
