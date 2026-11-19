<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AnsweredByTheOwner;
use Illuminate\Auth\Access\Response;

// A manager's move of a staff member to another branch, waiting for the owner (StaffTransferService).
// Who may be moved at all is StaffPolicy::moveToAnotherBranch.
class StaffTransferRequestPolicy
{
    use AnsweredByTheOwner;

    public function approve(User $user): Response
    {
        return $this->onlyTheOwner($user, 'Only the owner can confirm a move to another branch.');
    }

    public function decline(User $user): Response
    {
        return $this->onlyTheOwner($user, 'Only the owner can decline a move to another branch.');
    }
}
